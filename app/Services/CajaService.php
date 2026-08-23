<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\Cobro;
use App\Models\Gasto;
use App\Models\PagoProveedor;
use App\Models\Sale;
use Illuminate\Database\QueryException;
use RuntimeException;

class CajaService
{
    public function abrir(int $branchId, int $userId, float $fondoInicial): CashRegister
    {
        if ($this->hayAbierta($branchId)) {
            throw new RuntimeException('Ya hay un turno de caja abierto en esta sucursal.');
        }

        try {
            return CashRegister::create([
                'branch_id'      => $branchId,
                'user_id'        => $userId,
                'fondo_inicial'  => $fondoInicial,
                'fecha_apertura' => now(),
                'status'         => 'abierta',
            ]);
        } catch (QueryException $e) {
            // Protección a nivel BD contra doble apertura simultánea (open_marker único)
            throw new RuntimeException('Ya hay un turno de caja abierto en esta sucursal.');
        }
    }

    public function hayAbierta(int $branchId): bool
    {
        return CashRegister::where('branch_id', $branchId)->where('status', 'abierta')->exists();
    }

    /**
     * Todo movimiento en efectivo (venta, gasto, cobro, pago) debe registrarse
     * con un turno de caja abierto en la sucursal, para que el corte de caja
     * pueda conciliar el efectivo real contra lo esperado.
     */
    public function assertAbierta(?int $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        if (!$this->hayAbierta($branchId)) {
            throw new RuntimeException(
                'No hay un turno de caja abierto en esta sucursal. Abre caja antes de registrar movimientos en efectivo.'
            );
        }
    }

    public function cerrar(CashRegister $caja, float $efectivoContado, ?string $notas, int $closedById): CashRegister
    {
        $desde = $caja->fecha_apertura;
        $hasta = now();

        $ventasEfectivo = Sale::where('branch_id', $caja->branch_id)
            ->where('metodo_pago', 'efectivo')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$desde, $hasta])
            ->sum('total');

        $gastosPeriodo = Gasto::where('branch_id', $caja->branch_id)
            ->whereBetween('created_at', [$desde, $hasta])
            ->sum('monto');

        $cobrosEfectivo = Cobro::where('metodo_pago', 'efectivo')
            ->whereBetween('created_at', [$desde, $hasta])
            ->whereHas('cuentaPorCobrar', fn ($q) => $q->where('branch_id', $caja->branch_id))
            ->sum('monto');

        $pagosEfectivo = PagoProveedor::where('metodo_pago', 'efectivo')
            ->whereBetween('created_at', [$desde, $hasta])
            ->whereHas('cuentaPorPagar', fn ($q) => $q->where('branch_id', $caja->branch_id))
            ->sum('monto');

        $efectivoEsperado = $caja->fondo_inicial + $ventasEfectivo + $cobrosEfectivo - $gastosPeriodo - $pagosEfectivo;
        $diferencia = $efectivoContado - $efectivoEsperado;

        $caja->update([
            'closed_by'          => $closedById,
            'fecha_cierre'       => $hasta,
            'ventas_efectivo'    => $ventasEfectivo,
            'gastos_periodo'     => $gastosPeriodo,
            'cobros_efectivo'    => $cobrosEfectivo,
            'pagos_efectivo'     => $pagosEfectivo,
            'efectivo_esperado'  => $efectivoEsperado,
            'efectivo_contado'   => $efectivoContado,
            'diferencia'         => $diferencia,
            'status'             => 'cerrada',
            'notas'              => $notas,
        ]);

        return $caja->refresh();
    }
}
