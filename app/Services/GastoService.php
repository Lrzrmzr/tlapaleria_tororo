<?php

namespace App\Services;

use App\Models\Gasto;
use RuntimeException;

class GastoService
{
    public function __construct(private CajaService $caja)
    {
    }

    /**
     * Los gastos no tienen método de pago propio: siempre salen del efectivo
     * de caja, así que siempre requieren un turno abierto en la sucursal.
     */
    public function registrar(array $data, ?int $branchId, int $userId): Gasto
    {
        // Sin sucursal resuelta (root sin selección explícita) el gasto quedaría
        // huérfano y CajaService::assertAbierta(null) no lo detectaría por sí solo.
        if ($branchId === null) {
            throw new RuntimeException('Necesitas una sucursal asignada para registrar gastos.');
        }

        $this->caja->assertAbierta($branchId);

        return Gasto::create([
            'descripcion' => $data['descripcion'],
            'monto'       => $data['monto'],
            'user_id'     => $userId,
            'branch_id'   => $branchId,
            'fecha_gasto' => $data['fecha_gasto'] ?? now()->toDateString(),
        ]);
    }
}
