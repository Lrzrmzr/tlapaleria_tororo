<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchTransfer;
use App\Models\BranchTransferDetail;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class BranchTransferController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->user_branch_id;
        $isRoot   = $request->is_root ?? false;

        $query = BranchTransfer::with(['branchOrigen', 'branchDestino', 'user', 'details.product']);
        if (!$isRoot) {
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_origen_id', $branchId)->orWhere('branch_destino_id', $branchId);
            });
        }

        $traspasos = $query->latest()->get()->map(fn($t) => [
            'id'       => $t->id,
            'origen'   => $t->branchOrigen->name,
            'destino'  => $t->branchDestino->name,
            'user'     => $t->user->name,
            'fecha'    => $t->fecha->format('d/m/Y'),
            'status'   => $t->status,
            'notas'    => $t->notas,
            'items'    => $t->details->map(fn($d) => [
                'producto'  => $d->product->name ?? 'Producto eliminado',
                'quantity'  => $d->quantity,
            ])->values(),
        ]);

        $branches = Branch::where('active', true)->orderBy('name')->get(['id', 'name']);

        $productos = Product::where('active', true)->orderBy('name')->get(['id', 'name', 'barcode']);

        return Inertia::render('Traspasos/Index', [
            'traspasos' => $traspasos,
            'branches'  => $branches,
            'productos' => $productos,
            'isRoot'    => $isRoot,
            'userBranchId' => $branchId,
        ]);
    }

    public function store(Request $request)
    {
        $isRoot   = $request->is_root ?? false;
        $branchId = $request->user_branch_id;

        $data = $request->validate([
            'branch_origen_id'    => 'required|exists:branches,id',
            'branch_destino_id'   => 'required|different:branch_origen_id|exists:branches,id',
            'notas'               => 'nullable|string|max:500',
            'fecha'               => 'required|date',
            'items'               => 'required|array|min:1',
            'items.*.product_id'  => 'required|exists:products,id',
            'items.*.quantity'    => 'required|integer|min:1',
        ]);

        if (!$isRoot && $data['branch_origen_id'] != $branchId && $data['branch_destino_id'] != $branchId) {
            abort(403, 'Solo puedes hacer traspasos donde tu sucursal sea origen o destino.');
        }

        DB::beginTransaction();
        try {
            $transfer = BranchTransfer::create([
                'branch_origen_id'  => $data['branch_origen_id'],
                'branch_destino_id' => $data['branch_destino_id'],
                'user_id'           => Auth::id(),
                'status'            => 'completado',
                'notas'             => $data['notas'] ?? null,
                'fecha'             => $data['fecha'],
            ]);

            foreach ($data['items'] as $item) {
                $origenInv = BranchInventory::where('branch_id', $data['branch_origen_id'])
                    ->where('product_id', $item['product_id'])
                    ->first();

                if (!$origenInv || $origenInv->stock < $item['quantity']) {
                    $product = Product::find($item['product_id']);
                    throw new \Exception("Stock insuficiente de {$product?->name} en la sucursal de origen.");
                }

                BranchTransferDetail::create([
                    'branch_transfer_id' => $transfer->id,
                    'product_id'         => $item['product_id'],
                    'quantity'           => $item['quantity'],
                ]);

                InventoryMovement::aplicar(
                    $origenInv,
                    'traspaso_salida',
                    -$item['quantity'],
                    'Traspaso #' . $transfer->id,
                    $transfer,
                    Auth::id()
                );

                $destinoInv = BranchInventory::firstOrCreate(
                    ['branch_id' => $data['branch_destino_id'], 'product_id' => $item['product_id']],
                    ['stock' => 0, 'min_stock' => 0, 'max_stock' => 0, 'cost' => $origenInv->cost, 'active' => true]
                );

                InventoryMovement::aplicar(
                    $destinoInv,
                    'traspaso_entrada',
                    $item['quantity'],
                    'Traspaso #' . $transfer->id,
                    $transfer,
                    Auth::id()
                );
            }

            DB::commit();
            return redirect()->back()->with('success', 'Traspaso registrado. Inventario actualizado en ambas sucursales.');

        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}
