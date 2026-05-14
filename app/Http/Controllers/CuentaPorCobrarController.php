<?php

namespace App\Http\Controllers;

use App\Models\CuentaPorCobrar;
use App\Models\Cobro;
use App\Models\Customer;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CuentaPorCobrarController extends Controller
{
    public function index(Request $request)
    {
        $userBranchId = $request->user_branch_id;
        $isRoot       = $request->is_root ?? false;
        $user         = Auth::user();
        $roleName     = $user->roles->first()?->name ?? 'trabajador';

        $query = CuentaPorCobrar::with(['customer', 'branch', 'user', 'cobros.user']);

        if (!$isRoot) {
            $query->where('branch_id', $userBranchId);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('status'))      $query->where('status', $request->status);
        if ($request->filled('customer_id')) $query->where('customer_id', $request->customer_id);
        if ($request->filled('fecha_desde')) $query->where('fecha', '>=', $request->fecha_desde);
        if ($request->filled('fecha_hasta')) $query->where('fecha', '<=', $request->fecha_hasta);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('customer', fn($q) => $q->where('name', 'ilike', "%{$search}%"))
                  ->orWhere('concepto', 'ilike', "%{$search}%");
            });
        }

        $cuentas = $query->latest()->get()->map(fn($c) => $this->formatCuenta($c))->values();

        $statsQuery = CuentaPorCobrar::query();
        if (!$isRoot) $statsQuery->where('branch_id', $userBranchId);

        $stats = [
            'total_por_cobrar'   => (float) (clone $statsQuery)->whereIn('status', ['pendiente', 'parcial'])->sum('saldo'),
            'total_cobrado'      => (float) (clone $statsQuery)->sum('monto_cobrado'),
            'cuentas_vencidas'   => (clone $statsQuery)->vencidas()->count(),
            'clientes_con_deuda' => (clone $statsQuery)->whereIn('status', ['pendiente', 'parcial'])->distinct('customer_id')->count('customer_id'),
        ];

        $customers = Customer::orderBy('name')->get(['id', 'name', 'phone']);
        $branches  = $isRoot ? Branch::where('active', true)->orderBy('name')->get(['id', 'name']) : collect();

        return Inertia::render('CuentasPorCobrar/Index', [
            'cuentas'   => $cuentas,
            'stats'     => $stats,
            'customers' => $customers,
            'branches'  => $branches,
            'filters'   => $request->only(['status', 'customer_id', 'branch_id', 'fecha_desde', 'fecha_hasta', 'search']),
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
            'customer_id'       => 'nullable|exists:customers,id',
            'customer_name'     => 'required_without:customer_id|nullable|string|max:200',
            'customer_phone'    => 'nullable|string|max:30',
            'branch_id'         => 'required|exists:branches,id',
            'concepto'          => 'required|string|max:500',
            'monto_total'       => 'required|numeric|min:0.01',
            'fecha'             => 'required|date',
            'fecha_vencimiento' => 'nullable|date|after_or_equal:fecha',
            'notas'             => 'nullable|string|max:1000',
        ]);

        if (empty($data['customer_id']) && !empty($data['customer_name'])) {
            $customer = Customer::create([
                'name'   => $data['customer_name'],
                'phone'  => $data['customer_phone'] ?? null,
                'active' => true,
            ]);
            $data['customer_id'] = $customer->id;
        }

        if (!$isRoot) $data['branch_id'] = $userBranchId;

        $data['user_id'] = $user->id;
        $data['saldo']   = $data['monto_total'];
        $data['status']  = 'pendiente';

        unset($data['customer_name'], $data['customer_phone']);

        CuentaPorCobrar::create($data);

        return redirect()->back()->with('success', 'Venta a crédito registrada correctamente.');
    }

    public function update(Request $request, CuentaPorCobrar $cuentaPorCobrar)
    {
        $userBranchId = $request->user_branch_id;
        $isRoot       = $request->is_root ?? false;
        $user         = Auth::user();

        $this->authorizeBranchAccess($cuentaPorCobrar->branch_id, $userBranchId, $isRoot);

        $data = $request->validate([
            'customer_id'       => 'required|exists:customers,id',
            'concepto'          => 'required|string|max:500',
            'monto_total'       => 'required|numeric|min:0.01',
            'fecha'             => 'required|date',
            'fecha_vencimiento' => 'nullable|date|after_or_equal:fecha',
            'notas'             => 'nullable|string|max:1000',
        ]);

        $cuentaPorCobrar->update($data);
        $cuentaPorCobrar->recalcular();

        return redirect()->back()->with('success', 'Cuenta actualizada correctamente.');
    }

    public function destroy(CuentaPorCobrar $cuentaPorCobrar)
    {
        $this->authorizeRole(Auth::user(), ['root', 'administrador']);
        $this->authorizeBranchAccess($cuentaPorCobrar->branch_id, request()->user_branch_id, request()->is_root ?? false);

        $cuentaPorCobrar->delete();

        return redirect()->back()->with('success', 'Cuenta eliminada.');
    }

    public function storeCobro(Request $request, CuentaPorCobrar $cuentaPorCobrar)
    {
        $userBranchId = $request->user_branch_id;
        $isRoot       = $request->is_root ?? false;
        $user         = Auth::user();

        $this->authorizeBranchAccess($cuentaPorCobrar->branch_id, $userBranchId, $isRoot);

        if ($cuentaPorCobrar->status === 'liquidada') {
            return redirect()->back()->withErrors(['error' => 'Esta cuenta ya está totalmente liquidada.']);
        }

        $data = $request->validate([
            'monto'       => 'required|numeric|min:0.01|max:' . $cuentaPorCobrar->saldo,
            'fecha_cobro' => 'required|date',
            'metodo_pago' => 'required|in:efectivo,transferencia',
            'referencia'  => 'nullable|string|max:200',
            'notas'       => 'nullable|string|max:500',
        ]);

        $data['cuenta_por_cobrar_id'] = $cuentaPorCobrar->id;
        $data['user_id']              = $user->id;

        Cobro::create($data);

        return redirect()->back()->with('success', 'Cobro registrado correctamente.');
    }

    public function destroyCobro(Cobro $cobro)
    {
        $this->authorizeRole(Auth::user(), ['root', 'administrador']);
        $this->authorizeBranchAccess($cobro->cuentaPorCobrar->branch_id, request()->user_branch_id, request()->is_root ?? false);

        $cobro->delete();

        return redirect()->back()->with('success', 'Cobro eliminado.');
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

    private function formatCuenta(CuentaPorCobrar $c): array
    {
        return [
            'id'                => $c->id,
            'concepto'          => $c->concepto,
            'monto_total'       => (float) $c->monto_total,
            'monto_cobrado'     => (float) $c->monto_cobrado,
            'saldo'             => (float) $c->saldo,
            'fecha'             => $c->fecha?->toDateString(),
            'fecha_vencimiento' => $c->fecha_vencimiento?->toDateString(),
            'status'            => $c->status,
            'notas'             => $c->notas,
            'vencida'           => $c->fecha_vencimiento && $c->status !== 'liquidada' && $c->fecha_vencimiento->isPast(),
            'customer'          => $c->customer ? ['id' => $c->customer->id, 'name' => $c->customer->name, 'phone' => $c->customer->phone] : null,
            'branch'            => $c->branch   ? ['id' => $c->branch->id,   'name' => $c->branch->name]   : null,
            'user'              => $c->user     ? ['id' => $c->user->id,     'name' => $c->user->name]     : null,
            'cobros'            => $c->cobros->map(fn($p) => [
                'id'          => $p->id,
                'monto'       => (float) $p->monto,
                'fecha_cobro' => $p->fecha_cobro?->toDateString(),
                'metodo_pago' => $p->metodo_pago,
                'referencia'  => $p->referencia,
                'notas'       => $p->notas,
                'user'        => $p->user ? ['name' => $p->user->name] : null,
            ])->values()->toArray(),
        ];
    }
}
