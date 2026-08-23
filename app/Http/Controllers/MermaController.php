<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Merma;
use App\Models\Product;
use App\Services\MermaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use RuntimeException;

class MermaController extends Controller
{
    public function __construct(private MermaService $mermaService)
    {
    }

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
            // MermaPolicy::delete exige root o admin de sucursal — el botón
            // "Eliminar" se oculta para trabajadores en vez de darles un 403.
            'canDelete' => Auth::user()->isRoot() || Auth::user()->isBranchAdmin(),
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

        try {
            $this->mermaService->registrar($data, Auth::id());
        } catch (RuntimeException $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);
            return redirect()->back()->withErrors(['error' => 'Ocurrió un error inesperado al registrar la merma. Intenta de nuevo.']);
        }

        return redirect()->back()->with('success', 'Merma registrada. Inventario actualizado.');
    }

    public function destroy(Request $request, Merma $merma)
    {
        $this->authorize('delete', $merma);

        $this->mermaService->eliminar($merma, Auth::id());

        return redirect()->back()->with('success', 'Merma eliminada. El inventario fue restituido.');
    }
}
