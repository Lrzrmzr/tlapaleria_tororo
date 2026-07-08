<?php

namespace App\Http\Controllers;

use App\Models\CashRegister;
use App\Models\Sale;
use App\Models\Gasto;
use App\Models\Cobro;
use App\Models\PagoProveedor;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CajaController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->user_branch_id;
        $isRoot   = $request->is_root ?? false;

        $query = CashRegister::with(['branch', 'user', 'closedBy']);
        if (!$isRoot) {
            $query->where('branch_id', $branchId);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $abierta = (clone $query)->where('status', 'abierta')->latest('fecha_apertura')->first();
        $historial = (clone $query)->where('status', 'cerrada')->latest('fecha_cierre')->limit(30)->get();

        $branches = $isRoot ? Branch::where('active', true)->orderBy('name')->get(['id', 'name']) : collect();

        return Inertia::render('Caja/Index', [
            'cajaAbierta' => $abierta ? $this->formatCaja($abierta) : null,
            'historial'   => $historial->map(fn($c) => $this->formatCaja($c))->values(),
            'branches'    => $branches,
            'isRoot'      => $isRoot,
            'filters'     => ['branch_id' => $request->branch_id],
        ]);
    }

    public function abrir(Request $request)
    {
        $branchId = $request->user_branch_id;

        if (!$branchId) {
            return redirect()->back()->withErrors(['error' => 'Necesitas una sucursal asignada para abrir caja.']);
        }

        $yaAbierta = CashRegister::where('branch_id', $branchId)->where('status', 'abierta')->exists();
        if ($yaAbierta) {
            return redirect()->back()->withErrors(['error' => 'Ya hay un turno de caja abierto en esta sucursal.']);
        }

        $request->validate([
            'fondo_inicial' => 'required|numeric|min:0',
        ]);

        try {
            CashRegister::create([
                'branch_id'      => $branchId,
                'user_id'        => Auth::id(),
                'fondo_inicial'  => $request->fondo_inicial,
                'fecha_apertura' => now(),
                'status'         => 'abierta',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Protección a nivel BD contra doble apertura simultánea (índice único parcial)
            return redirect()->back()->withErrors(['error' => 'Ya hay un turno de caja abierto en esta sucursal.']);
        }

        return redirect()->back()->with('success', 'Turno de caja abierto.');
    }

    public function cerrar(Request $request, CashRegister $caja)
    {
        $isRoot = $request->is_root ?? false;
        if (!$isRoot && $caja->branch_id !== $request->user_branch_id) {
            abort(403, 'No tienes acceso a la caja de otra sucursal.');
        }

        if ($caja->status === 'cerrada') {
            return redirect()->back()->withErrors(['error' => 'Este turno ya está cerrado.']);
        }

        $request->validate([
            'efectivo_contado' => 'required|numeric|min:0',
            'notas'            => 'nullable|string|max:1000',
        ]);

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
            ->whereHas('cuentaPorCobrar', fn($q) => $q->where('branch_id', $caja->branch_id))
            ->sum('monto');

        $pagosEfectivo = PagoProveedor::where('metodo_pago', 'efectivo')
            ->whereBetween('created_at', [$desde, $hasta])
            ->whereHas('cuentaPorPagar', fn($q) => $q->where('branch_id', $caja->branch_id))
            ->sum('monto');

        $efectivoEsperado = $caja->fondo_inicial + $ventasEfectivo + $cobrosEfectivo - $gastosPeriodo - $pagosEfectivo;
        $diferencia = $request->efectivo_contado - $efectivoEsperado;

        $caja->update([
            'closed_by'          => Auth::id(),
            'fecha_cierre'       => $hasta,
            'ventas_efectivo'    => $ventasEfectivo,
            'gastos_periodo'     => $gastosPeriodo,
            'cobros_efectivo'    => $cobrosEfectivo,
            'pagos_efectivo'     => $pagosEfectivo,
            'efectivo_esperado'  => $efectivoEsperado,
            'efectivo_contado'   => $request->efectivo_contado,
            'diferencia'         => $diferencia,
            'status'             => 'cerrada',
            'notas'              => $request->notas,
        ]);

        $msg = abs($diferencia) < 0.01
            ? 'Caja cerrada sin diferencias.'
            : ($diferencia > 0
                ? 'Caja cerrada con sobrante de $' . number_format($diferencia, 2) . '.'
                : 'Caja cerrada con faltante de $' . number_format(abs($diferencia), 2) . '.');

        return redirect()->back()->with('success', $msg);
    }

    private function formatCaja(CashRegister $c): array
    {
        return [
            'id'                => $c->id,
            'branch'            => $c->branch ? ['id' => $c->branch->id, 'name' => $c->branch->name] : null,
            'user'              => $c->user ? $c->user->name : null,
            'closed_by'         => $c->closedBy ? $c->closedBy->name : null,
            'fondo_inicial'     => (float) $c->fondo_inicial,
            'fecha_apertura'    => $c->fecha_apertura->format('d/m/Y H:i'),
            'fecha_cierre'      => $c->fecha_cierre?->format('d/m/Y H:i'),
            'ventas_efectivo'   => (float) $c->ventas_efectivo,
            'gastos_periodo'    => (float) $c->gastos_periodo,
            'cobros_efectivo'   => (float) $c->cobros_efectivo,
            'pagos_efectivo'    => (float) $c->pagos_efectivo,
            'efectivo_esperado' => (float) $c->efectivo_esperado,
            'efectivo_contado'  => (float) $c->efectivo_contado,
            'diferencia'        => (float) $c->diferencia,
            'status'            => $c->status,
            'notas'             => $c->notas,
        ];
    }
}
