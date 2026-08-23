<?php

namespace App\Http\Controllers;

use App\Models\CashRegister;
use App\Models\Branch;
use App\Services\CajaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use RuntimeException;

class CajaController extends Controller
{
    public function __construct(private CajaService $cajaService)
    {
    }

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
            'historial'   => $historial->map(fn ($c) => $this->formatCaja($c))->values(),
            'branches'    => $branches,
            'isRoot'      => $isRoot,
            'filters'     => ['branch_id' => $request->branch_id],
        ]);
    }

    public function abrir(Request $request)
    {
        $isRoot = $request->is_root ?? false;

        $request->validate([
            'fondo_inicial' => 'required|numeric|min:0',
            'branch_id'     => $isRoot ? 'required|exists:branches,id' : 'nullable',
        ]);

        // Root no tiene sucursal propia: debe elegir explícitamente para cuál
        // sucursal abre el turno (ver selector en Caja/Index.vue).
        $branchId = $isRoot ? (int) $request->branch_id : $request->user_branch_id;

        if (!$branchId) {
            return redirect()->back()->withErrors(['error' => 'Necesitas una sucursal asignada para abrir caja.']);
        }

        try {
            $this->cajaService->abrir($branchId, Auth::id(), (float) $request->fondo_inicial);
        } catch (RuntimeException $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Turno de caja abierto.');
    }

    public function cerrar(Request $request, CashRegister $caja)
    {
        $this->authorize('cerrar', $caja);

        if ($caja->status === 'cerrada') {
            return redirect()->back()->withErrors(['error' => 'Este turno ya está cerrado.']);
        }

        $request->validate([
            'efectivo_contado' => 'required|numeric|min:0',
            'notas'            => 'nullable|string|max:1000',
        ]);

        $caja = $this->cajaService->cerrar($caja, (float) $request->efectivo_contado, $request->notas, Auth::id());

        $diferencia = (float) $caja->diferencia;
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
