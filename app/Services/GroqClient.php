<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Único punto de salida hacia Groq. Se encarga de que el negocio nunca pase
 * del límite gratuito de la plataforma:
 *
 *  - Groq devuelve en cada respuesta sus propios límites
 *    (x-ratelimit-limit/remaining/reset-requests = peticiones por DÍA,
 *    x-ratelimit-*-tokens = tokens por MINUTO). Se guardan por modelo y,
 *    si las peticiones restantes llegan a 0, no se vuelve a llamar hasta el
 *    reinicio que indicó Groq.
 *  - Un 429 bloquea las llamadas durante el retry-after que manda Groq.
 *  - GROQ_DAILY_LIMIT (opcional) fija un tope propio, más bajo que el de
 *    Groq, contado localmente por día.
 *
 * En plan gratuito Groq no cobra: al pasarse responde 429. Este cliente evita
 * incluso llegar a esos 429 y deja visible cuánto queda.
 */
class GroqClient
{
    private const PREFIJO = 'groq:';

    public function __construct(private readonly Cache $cache)
    {
    }

    public function configurado(): bool
    {
        return !empty(config('services.groq.api_key'));
    }

    /**
     * @throws GroqCuotaAgotada si el límite del día (de Groq o propio) ya se alcanzó.
     */
    public function chat(string $model, array $payload, int $timeout): Response
    {
        $this->asegurarCuota($model);

        $resp = Http::withToken((string) config('services.groq.api_key'))
            ->timeout($timeout)
            ->post(config('services.groq.url'), ['model' => $model] + $payload);

        $this->registrar($model, $resp);

        if ($resp->status() === 429) {
            throw new GroqCuotaAgotada($this->mensajeBloqueo($model));
        }

        return $resp;
    }

    /**
     * Estado de la cuota de un modelo, para mostrarlo en la interfaz.
     * Los valores de Groq son null hasta la primera llamada del día (Groq no
     * tiene un endpoint para consultarlos sin gastar una petición).
     */
    public function estado(string $model): array
    {
        $limites = $this->cache->get($this->clave('limites', $model));
        $ahora   = CarbonImmutable::now();

        $limiteDia = $restantesDia = $reiniciaDia = null;
        if ($limites) {
            $reinicio = CarbonImmutable::createFromTimestamp($limites['reset_requests_at']);
            $limiteDia = $limites['limit_requests'];
            // Pasado el reinicio que anunció Groq, el contador vuelve al tope.
            $restantesDia = $reinicio->isPast() ? $limiteDia : $limites['remaining_requests'];
            $reiniciaDia  = $reinicio->isPast() ? null : $reinicio->toIso8601String();
        }

        $usadasHoy = (int) $this->cache->get($this->claveUsoHoy($model), 0);
        $topeLocal = $this->topeLocal();
        if ($topeLocal !== null) {
            $restantesLocal = max(0, $topeLocal - $usadasHoy);
            $restantesDia   = $restantesDia === null ? $restantesLocal : min($restantesDia, $restantesLocal);
            $limiteDia      = $limiteDia === null ? $topeLocal : min($limiteDia, $topeLocal);
        }

        $bloqueadoHasta = $this->cache->get($this->clave('bloqueado', $model));

        return [
            'modelo'               => $model,
            'limite_dia'           => $limiteDia,
            'restantes_dia'        => $restantesDia,
            'reinicia'             => $reiniciaDia,
            'usadas_hoy'           => $usadasHoy,
            'tope_local'           => $topeLocal,
            'tokens_minuto_limite' => $limites['limit_tokens'] ?? null,
            'tokens_minuto_restantes' => $limites['remaining_tokens'] ?? null,
            'bloqueado_hasta'      => $bloqueadoHasta && $bloqueadoHasta > $ahora->getTimestamp()
                ? CarbonImmutable::createFromTimestamp($bloqueadoHasta)->toIso8601String()
                : null,
            'actualizado'          => isset($limites['at']) ? CarbonImmutable::createFromTimestamp($limites['at'])->toIso8601String() : null,
        ];
    }

    private function asegurarCuota(string $model): void
    {
        $bloqueadoHasta = $this->cache->get($this->clave('bloqueado', $model));
        if ($bloqueadoHasta && $bloqueadoHasta > time()) {
            throw new GroqCuotaAgotada($this->mensajeBloqueo($model));
        }

        $limites = $this->cache->get($this->clave('limites', $model));
        if ($limites
            && $limites['remaining_requests'] !== null
            && $limites['remaining_requests'] <= 0
            && $limites['reset_requests_at'] > time()) {
            throw new GroqCuotaAgotada($this->mensajeBloqueo($model));
        }

        $tope = $this->topeLocal();
        if ($tope !== null && (int) $this->cache->get($this->claveUsoHoy($model), 0) >= $tope) {
            throw new GroqCuotaAgotada('Se alcanzó el tope diario de consultas de IA configurado (' . $tope . '). Se reinicia a medianoche.');
        }
    }

    private function registrar(string $model, Response $resp): void
    {
        // Contador propio del día (cuenta también los 429: Groq los registra).
        $claveUso = $this->claveUsoHoy($model);
        $this->cache->add($claveUso, 0, now()->endOfDay());
        $this->cache->increment($claveUso);

        $limitReq = $resp->header('x-ratelimit-limit-requests');
        if ($limitReq !== '') {
            $ahora = time();
            $this->cache->put($this->clave('limites', $model), [
                'limit_requests'     => (int) $limitReq,
                'remaining_requests' => $this->entero($resp->header('x-ratelimit-remaining-requests')),
                'reset_requests_at'  => $ahora + (int) ceil(self::segundos($resp->header('x-ratelimit-reset-requests'))),
                'limit_tokens'       => $this->entero($resp->header('x-ratelimit-limit-tokens')),
                'remaining_tokens'   => $this->entero($resp->header('x-ratelimit-remaining-tokens')),
                'at'                 => $ahora,
            ], now()->addDays(2));
        }

        if ($resp->status() === 429) {
            $espera = (int) ceil(self::segundos($resp->header('retry-after')) ?: 60);
            $this->cache->put($this->clave('bloqueado', $model), time() + $espera, $espera);
        }
    }

    private function mensajeBloqueo(string $model): string
    {
        $estado  = $this->estado($model);
        $cuando  = $estado['bloqueado_hasta'] ?? $estado['reinicia'];
        $detalle = $cuando
            ? ' Se reinicia ' . CarbonImmutable::parse($cuando)->locale('es')->diffForHumans() . '.'
            : '';

        return 'Se alcanzó el límite gratuito de consultas de IA de Groq.' . $detalle;
    }

    /**
     * Convierte las duraciones de Groq ("2m59.56s", "7.66s", "120ms", "1h2m3s"
     * o un número de segundos en retry-after) a segundos.
     */
    public static function segundos(?string $valor): float
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return 0.0;
        }
        if (is_numeric($valor)) {
            return (float) $valor;
        }

        preg_match_all('/(\d+(?:\.\d+)?)(ms|h|m|s)/', $valor, $partes, PREG_SET_ORDER);
        $total = 0.0;
        foreach ($partes as [, $n, $unidad]) {
            $total += (float) $n * match ($unidad) {
                'h'  => 3600,
                'm'  => 60,
                's'  => 1,
                'ms' => 0.001,
            };
        }

        return $total;
    }

    private function topeLocal(): ?int
    {
        $tope = config('services.groq.daily_limit');

        return is_numeric($tope) && (int) $tope > 0 ? (int) $tope : null;
    }

    private function entero(?string $valor): ?int
    {
        return is_numeric($valor) ? (int) $valor : null;
    }

    private function clave(string $tipo, string $model): string
    {
        return self::PREFIJO . $tipo . ':' . $model;
    }

    private function claveUsoHoy(string $model): string
    {
        return self::PREFIJO . 'uso:' . now()->toDateString() . ':' . $model;
    }
}
