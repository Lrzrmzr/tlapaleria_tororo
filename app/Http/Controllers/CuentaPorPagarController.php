<?php

namespace App\Http\Controllers;

use App\Models\CuentaPorPagar;
use App\Models\PagoProveedor;
use App\Models\Supplier;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CuentaPorPagarController extends Controller
{
    public function index(Request $request)
    {
        $userBranchId = $request->user_branch_id;
        $isRoot       = $request->is_root ?? false;
        $user         = Auth::user();
        $roleName     = $user->roles->first()?->name ?? 'trabajador';

        $query = CuentaPorPagar::with(['supplier', 'branch', 'user', 'pagos.user']);

        if (!$isRoot) {
            $query->where('branch_id', $userBranchId);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('status'))      $query->where('status', $request->status);
        if ($request->filled('supplier_id')) $query->where('supplier_id', $request->supplier_id);
        if ($request->filled('fecha_desde')) $query->where('fecha_nota', '>=', $request->fecha_desde);
        if ($request->filled('fecha_hasta')) $query->where('fecha_nota', '<=', $request->fecha_hasta);

        $cuentas = $query->latest()->paginate(50)->through(fn($c) => $this->formatCuenta($c));

        $statsQuery = CuentaPorPagar::query();
        if (!$isRoot) $statsQuery->where('branch_id', $userBranchId);

        $stats = [
            'total_deuda'           => (float) (clone $statsQuery)->whereIn('status', ['pendiente', 'parcial'])->sum('saldo'),
            'total_pagado'          => (float) (clone $statsQuery)->sum('monto_pagado'),
            'cuentas_vencidas'      => (clone $statsQuery)->vencidas()->count(),
            'proveedores_con_deuda' => (clone $statsQuery)->whereIn('status', ['pendiente', 'parcial'])->distinct('supplier_id')->count('supplier_id'),
        ];

        $suppliers = Supplier::active()->orderBy('name')->get(['id', 'name']);
        $branches  = $isRoot ? Branch::where('active', true)->orderBy('name')->get(['id', 'name']) : collect();

        return Inertia::render('CuentasPorPagar/Index', [
            'cuentas'   => $cuentas,
            'stats'     => $stats,
            'suppliers' => $suppliers,
            'branches'  => $branches,
            'filters'   => $request->only(['status', 'supplier_id', 'branch_id', 'fecha_desde', 'fecha_hasta']),
            'user'      => [
                'id'        => $user->id,
                'name'      => $user->name,
                'role'      => $roleName,
                'isRoot'    => $isRoot,
                'branchId'  => $userBranchId,
                'canDelete' => in_array($roleName, ['root', 'administrador']),
                'canCreate' => true,
                'canEdit'   => true,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $userBranchId = $request->user_branch_id;
        $isRoot       = $request->is_root ?? false;
        $user         = Auth::user();

        $data = $request->validate([
            'supplier_id'       => 'required|exists:suppliers,id',
            'branch_id'         => 'required|exists:branches,id',
            'numero_nota'       => 'nullable|string|max:100',
            'concepto'          => 'required|string|max:500',
            'monto_total'       => 'required|numeric|min:0.01',
            'fecha_nota'        => 'required|date',
            'fecha_vencimiento' => 'nullable|date|after_or_equal:fecha_nota',
            'notas'             => 'nullable|string|max:1000',
        ]);

        if (!$isRoot) $data['branch_id'] = $userBranchId;

        $data['user_id'] = $user->id;
        $data['saldo']   = $data['monto_total'];
        $data['status']  = 'pendiente';

        CuentaPorPagar::create($data);

        return redirect()->back()->with('success', 'Nota de proveedor registrada correctamente.');
    }

    public function update(Request $request, CuentaPorPagar $cuentaPorPagar)
    {
        $userBranchId = $request->user_branch_id;
        $isRoot       = $request->is_root ?? false;
        $user         = Auth::user();

        $this->authorizeBranchAccess($cuentaPorPagar->branch_id, $userBranchId, $isRoot);

        $data = $request->validate([
            'supplier_id'       => 'required|exists:suppliers,id',
            'numero_nota'       => 'nullable|string|max:100',
            'concepto'          => 'required|string|max:500',
            'monto_total'       => 'required|numeric|min:0.01',
            'fecha_nota'        => 'required|date',
            'fecha_vencimiento' => 'nullable|date|after_or_equal:fecha_nota',
            'notas'             => 'nullable|string|max:1000',
        ]);

        $cuentaPorPagar->update($data);
        $cuentaPorPagar->recalcular();

        return redirect()->back()->with('success', 'Cuenta actualizada correctamente.');
    }

    public function destroy(CuentaPorPagar $cuentaPorPagar)
    {
        $this->authorizeRole(Auth::user(), ['root', 'administrador']);
        $this->authorizeBranchAccess($cuentaPorPagar->branch_id, request()->user_branch_id, request()->is_root ?? false);

        $cuentaPorPagar->delete();

        return redirect()->back()->with('success', 'Cuenta eliminada.');
    }

    public function storePago(Request $request, CuentaPorPagar $cuentaPorPagar)
    {
        $userBranchId = $request->user_branch_id;
        $isRoot       = $request->is_root ?? false;
        $user         = Auth::user();

        $this->authorizeBranchAccess($cuentaPorPagar->branch_id, $userBranchId, $isRoot);

        if ($cuentaPorPagar->status === 'pagada') {
            return redirect()->back()->withErrors(['error' => 'Esta cuenta ya está totalmente pagada.']);
        }

        $data = $request->validate([
            'monto'       => 'required|numeric|min:0.01|max:' . $cuentaPorPagar->saldo,
            'fecha_pago'  => 'required|date',
            'metodo_pago' => 'required|in:efectivo,transferencia,cheque',
            'referencia'  => 'nullable|string|max:200',
            'notas'       => 'nullable|string|max:500',
        ]);

        $data['cuenta_por_pagar_id'] = $cuentaPorPagar->id;
        $data['user_id']             = $user->id;

        PagoProveedor::create($data);

        return redirect()->back()->with('success', 'Pago registrado correctamente.');
    }

    public function destroyPago(PagoProveedor $pago)
    {
        $this->authorizeRole(Auth::user(), ['root', 'administrador']);
        $this->authorizeBranchAccess($pago->cuentaPorPagar->branch_id, request()->user_branch_id, request()->is_root ?? false);

        $pago->delete();

        return redirect()->back()->with('success', 'Pago eliminado.');
    }

    private function authorizeRole(User $user, array $allowed): void
    {
        if (!in_array($user->roles->first()?->name, $allowed)) {
            abort(403, 'No tienes permisos para realizar esta acción.');
        }
    }

    private function authorizeBranchAccess(?int $resourceBranchId, ?int $userBranchId, bool $isRoot): void
    {
        if ($isRoot) return;
        if ($resourceBranchId !== $userBranchId) abort(403, 'No tienes acceso a los datos de otra sucursal.');
    }

    private function formatCuenta(CuentaPorPagar $c): array
    {
        return [
            'id'                => $c->id,
            'numero_nota'       => $c->numero_nota,
            'concepto'          => $c->concepto,
            'monto_total'       => (float) $c->monto_total,
            'monto_pagado'      => (float) $c->monto_pagado,
            'saldo'             => (float) $c->saldo,
            'fecha_nota'        => $c->fecha_nota?->toDateString(),
            'fecha_vencimiento' => $c->fecha_vencimiento?->toDateString(),
            'status'            => $c->status,
            'notas'             => $c->notas,
            'vencida'           => $c->fecha_vencimiento && $c->status !== 'pagada' && $c->fecha_vencimiento->isPast(),
            'supplier'          => $c->supplier ? ['id' => $c->supplier->id, 'name' => $c->supplier->name] : null,
            'branch'            => $c->branch   ? ['id' => $c->branch->id,   'name' => $c->branch->name]   : null,
            'user'              => $c->user     ? ['id' => $c->user->id,     'name' => $c->user->name]     : null,
            'pagos'             => $c->pagos->map(fn($p) => [
                'id'          => $p->id,
                'monto'       => (float) $p->monto,
                'fecha_pago'  => $p->fecha_pago?->toDateString(),
                'metodo_pago' => $p->metodo_pago,
                'referencia'  => $p->referencia,
                'notas'       => $p->notas,
                'user'        => $p->user ? ['name' => $p->user->name] : null,
            ])->values()->toArray(),
        ];
    }
}
