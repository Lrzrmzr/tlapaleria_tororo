<?php

namespace App\Services;

use App\Models\Cobro;
use App\Models\CuentaPorCobrar;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CuentaPorCobrarService
{
    public function __construct(private CajaService $caja)
    {
    }

    /**
     * Registra un cobro bajo un lock de fila sobre la cuenta: sin esto, dos
     * cobros concurrentes podían leer el mismo saldo y ambos pasar la
     * validación de tope, sobrepagando la cuenta.
     */
    public function registrarCobro(CuentaPorCobrar $cuenta, array $data, int $userId): Cobro
    {
        if ($data['metodo_pago'] === 'efectivo') {
            $this->caja->assertAbierta($cuenta->branch_id);
        }

        return DB::transaction(function () use ($cuenta, $data, $userId) {
            /** @var CuentaPorCobrar $cuentaBloqueada */
            $cuentaBloqueada = CuentaPorCobrar::whereKey($cuenta->id)->lockForUpdate()->firstOrFail();

            if ($cuentaBloqueada->status === 'liquidada') {
                throw new RuntimeException('Esta cuenta ya está totalmente liquidada.');
            }

            if ($data['monto'] > (float) $cuentaBloqueada->saldo) {
                throw new RuntimeException('El monto excede el saldo pendiente de la cuenta.');
            }

            $cobro = Cobro::create([
                'cuenta_por_cobrar_id' => $cuentaBloqueada->id,
                'user_id'              => $userId,
                'monto'                => $data['monto'],
                'fecha_cobro'          => $data['fecha_cobro'],
                'metodo_pago'          => $data['metodo_pago'],
                'referencia'           => $data['referencia'] ?? null,
                'notas'                => $data['notas'] ?? null,
            ]);

            return $cobro;
        });
    }
}
