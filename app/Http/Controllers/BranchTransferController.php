<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\Product;
use App\Services\BranchTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use RuntimeException;

class BranchTransferController extends Controller
{
    public function __construct(private BranchTransferService $transferService)
    {
    }

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
        $data = $request->validate([
            'branch_origen_id'    => 'required|exists:branches,id',
            'branch_destino_id'   => 'required|different:branch_origen_id|exists:branches,id',
            'notas'               => 'nullable|string|max:500',
            'fecha'               => 'required|date',
            'items'               => 'required|array|min:1',
            'items.*.product_id'  => 'required|exists:products,id',
            'items.*.quantity'    => 'required|integer|min:1',
        ]);

        $this->authorize('create', [BranchTransfer::class, (int) $data['branch_origen_id'], (int) $data['branch_destino_id']]);

        try {
            $this->transferService->registrar(
                $data['branch_origen_id'],
                $data['branch_destino_id'],
                $data['items'],
                $data['fecha'],
                $data['notas'] ?? null,
                Auth::id()
            );
        } catch (RuntimeException $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);
            return redirect()->back()->withErrors(['error' => 'Ocurrió un error inesperado al registrar el traspaso. Intenta de nuevo.']);
        }

        return redirect()->back()->with('success', 'Traspaso registrado. Inventario actualizado en ambas sucursales.');
    }
}
