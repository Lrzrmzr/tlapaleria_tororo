<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\Merma;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MermaController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->user_branch_id;
        $isRoot   = $request->is_root ?? false;

        $query = Merma::with(['branch', 'product', 'user'])->latest('fecha');
        if (!$isRoot) {
            $query->where('branch_id', $branchId);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $mermas = $query->get()->map(fn($m) => [
            'id'       => $m->id,
            'producto' => $m->product->name ?? 'Producto eliminado',
            'branch'   => $m->branch->name ?? '',
            'quantity' => $m->quantity,
            'motivo'   => $m->motivo,
            'notas'    => $m->notas,
            'user'     => $m->user->name ?? '',
            'fecha'    => $m->fecha->format('d/m/Y'),
        ]);

        $statsQuery = Merma::query();
        if (!$isRoot) {
            $statsQuery->where('branch_id', $branchId);
        }
        $stats = [
            'total'        => (clone $statsQuery)->count(),
            'unidades'     => (clone $statsQuery)->sum('quantity'),
            'este_mes'     => (clone $statsQuery)->whereMonth('fecha', now()->month)->whereYear('fecha', now()->year)->count(),
        ];

        $productos = Product::where('active', true)->orderBy('name')->get(['id', 'name', 'barcode']);
        $branches  = $isRoot ? Branch::where('active', true)->orderBy('name')->get(['id', 'name']) : collect();

        return Inertia::render('Mermas/Index', [
            'mermas'    => $mermas,
            'stats'     => $stats,
            'productos' => $productos,
            'branches'  => $branches,
            'isRoot'    => $isRoot,
            'filters'   => ['branch_id' => $request->branch_id],
        ]);
    }

    public function store(Request $request)
    {
        $branchId = $request->user_branch_id;
        $isRoot   = $request->is_root ?? false;

        $data = $request->validate([
            'branch_id'  => $isRoot ? 'required|exists:branches,id' : 'nullable',
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'required|integer|min:1',
            'motivo'     => 'required|in:dañado,caducado,robo,extravio,otro',
            'notas'      => 'nullable|string|max:500',
            'fecha'      => 'required|date',
        ]);

        $data['branch_id'] = $isRoot ? $data['branch_id'] : $branchId;

        $inventario = BranchInventory::where('branch_id', $data['branch_id'])
            ->where('product_id', $data['product_id'])
            ->first();

        if (!$inventario || $inventario->stock < $data['quantity']) {
            return redirect()->back()->withErrors(['error' => 'Stock insuficiente para registrar esta merma.']);
        }

        DB::beginTransaction();
        try {
            $merma = Merma::create([
                'branch_id'  => $data['branch_id'],
                'product_id' => $data['product_id'],
                'quantity'   => $data['quantity'],
                'motivo'     => $data['motivo'],
                'notas'      => $data['notas'] ?? null,
                'user_id'    => Auth::id(),
                'fecha'      => $data['fecha'],
            ]);

            InventoryMovement::aplicar(
                $inventario,
                'merma',
                -$data['quantity'],
                ucfirst($data['motivo']) . ' — Merma #' . $merma->id,
                $merma,
                Auth::id()
            );

            DB::commit();
            return redirect()->back()->with('success', 'Merma registrada. Inventario actualizado.');

        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function destroy(Request $request, Merma $merma)
    {
        $isRoot = $request->is_root ?? false;
        if (!$isRoot && $merma->branch_id !== $request->user_branch_id) {
            abort(403, 'No tienes acceso a datos de otra sucursal.');
        }

        $merma->delete();
        return redirect()->back()->with('success', 'Registro de merma eliminado (el stock no se revierte automáticamente).');
    }
}
