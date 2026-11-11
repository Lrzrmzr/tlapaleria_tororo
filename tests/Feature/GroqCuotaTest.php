<?php

namespace Tests\Feature;

use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

/**
 * El asistente debe quedarse dentro del límite gratuito de Groq: respeta los
 * límites que Groq informa en sus headers, se detiene ante un 429 y expone
 * cuántas consultas quedan en el día.
 */
class GroqCuotaTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.groq.api_key' => 'test-key',
            'services.groq.model'   => 'modelo-texto',
            'services.groq.daily_limit' => null,
        ]);
    }

    private function respuestaGroq(int $restantes, string $reinicio = '2m30s'): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(
            ['choices' => [['message' => ['content' => json_encode(['sql' => null, 'descripcion' => ''])]]]],
            200,
            [
                'x-ratelimit-limit-requests'     => '1000',
                'x-ratelimit-remaining-requests' => (string) $restantes,
                'x-ratelimit-reset-requests'     => $reinicio,
                'x-ratelimit-limit-tokens'       => '12000',
                'x-ratelimit-remaining-tokens'   => '11400',
            ]
        );
    }

    public function test_guarda_los_limites_que_informa_groq_y_los_expone(): void
    {
        Http::fake(['*' => $this->respuestaGroq(998)]);
        $admin = $this->makeUser($this->makeBranch(), 'administrador');

        $this->actingAs($admin)->postJson('/asistente', ['pregunta' => 'hola'])->assertOk();

        $this->actingAs($admin)->getJson('/asistente/cuota')
            ->assertOk()
            ->assertJson([
                'limite_dia'              => 1000,
                'restantes_dia'           => 998,
                'preguntas_restantes'     => 499,
                'tokens_minuto_limite'    => 12000,
                'tokens_minuto_restantes' => 11400,
                'usadas_hoy'              => 1,
            ]);
    }

    public function test_sin_cuota_restante_no_vuelve_a_llamar_a_groq(): void
    {
        Http::fake(['*' => $this->respuestaGroq(0, '3h')]);
        $admin = $this->makeUser($this->makeBranch(), 'administrador');

        $this->actingAs($admin)->postJson('/asistente', ['pregunta' => 'primera'])->assertOk();
        Http::assertSentCount(1);

        $this->actingAs($admin)->postJson('/asistente', ['pregunta' => 'segunda'])
            ->assertStatus(429)
            ->assertJsonPath('cuota.restantes_dia', 0);

        Http::assertSentCount(1);
    }

    public function test_un_429_de_groq_bloquea_hasta_el_retry_after(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'rate limit']], 429, ['retry-after' => '120'])]);
        $admin = $this->makeUser($this->makeBranch(), 'administrador');

        $this->actingAs($admin)->postJson('/asistente', ['pregunta' => 'una'])->assertStatus(429);
        $this->actingAs($admin)->postJson('/asistente', ['pregunta' => 'otra'])->assertStatus(429);

        Http::assertSentCount(1);
        $this->assertNotNull(app(GroqClient::class)->estado('modelo-texto')['bloqueado_hasta']);
    }

    public function test_el_tope_local_corta_antes_que_groq(): void
    {
        config(['services.groq.daily_limit' => 2]);
        Http::fake(['*' => $this->respuestaGroq(900)]);
        $admin = $this->makeUser($this->makeBranch(), 'administrador');

        // Sin SQL solo se hace una petición por pregunta.
        $this->actingAs($admin)->postJson('/asistente', ['pregunta' => 'a'])->assertOk();
        $this->actingAs($admin)->postJson('/asistente', ['pregunta' => 'b'])->assertOk();
        $this->actingAs($admin)->postJson('/asistente', ['pregunta' => 'c'])->assertStatus(429);

        Http::assertSentCount(2);
        $this->assertSame(0, app(GroqClient::class)->estado('modelo-texto')['restantes_dia']);
    }

    public function test_el_trabajador_no_consulta_la_cuota(): void
    {
        $trabajador = $this->makeUser($this->makeBranch(), 'trabajador');

        $this->actingAs($trabajador)->getJson('/asistente/cuota')->assertForbidden();
    }

    public static function duraciones(): array
    {
        return [
            ['2m59.56s', 179.56],
            ['7.66s', 7.66],
            ['120ms', 0.12],
            ['1h2m3s', 3723.0],
            ['30', 30.0],
            ['', 0.0],
        ];
    }

    #[DataProvider('duraciones')]
    public function test_interpreta_las_duraciones_de_groq(string $valor, float $esperado): void
    {
        $this->assertEqualsWithDelta($esperado, GroqClient::segundos($valor), 0.001);
    }
}
