<?php

namespace App\Services;

use App\Models\CuentaPorPagar;
use App\Models\PagoProveedor;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CuentaPorPagarService
{
    public function __construct(private CajaService $caja)
    {
    }

    /**
     * Registra un pago bajo un lock de fila sobre la cuenta: sin esto, dos
     * pagos concurrentes podían leer el mismo saldo y ambos pasar la
     * validación de tope, sobrepagando la cuenta.
     */
    public function registrarPago(CuentaPorPagar $cuenta, array $data, int $userId): PagoProveedor
    {
        if ($data['metodo_pago'] === 'efectivo') {
            $this->caja->assertAbierta($cuenta->branch_id);
        }

        return DB::transaction(function () use ($cuenta, $data, $userId) {
            /** @var CuentaPorPagar $cuentaBloqueada */
            $cuentaBloqueada = CuentaPorPagar::whereKey($cuenta->id)->lockForUpdate()->firstOrFail();

            if ($cuentaBloqueada->status === 'pagada') {
                throw new RuntimeException('Esta cuenta ya está totalmente pagada.');
            }

            if ($data['monto'] > (float) $cuentaBloqueada->saldo) {
                throw new RuntimeException('El monto excede el saldo pendiente de la cuenta.');
            }

            return PagoProveedor::create([
                'cuenta_por_pagar_id' => $cuentaBloqueada->id,
                'user_id'             => $userId,
                'monto'               => $data['monto'],
                'fecha_pago'          => $data['fecha_pago'],
                'metodo_pago'         => $data['metodo_pago'],
                'referencia'          => $data['referencia'] ?? null,
                'notas'               => $data['notas'] ?? null,
            ]);
        });
    }
}
