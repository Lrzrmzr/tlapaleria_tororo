<?php
namespace App\Http\Controllers;

use App\Http\Requests\StoreFaltanteRequest;
use App\Models\Faltante;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class FaltanteController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->user_branch_id;
        $isRoot   = $request->is_root ?? false;

        $query = Faltante::with('user')->latest();

        if (!$isRoot) {
            $query->where('branch_id', $branchId);
        }

        if ($request->has('confirmado')) {
            $query->where('confirmado', $request->confirmado);
        }
        // Solo filtra por pedido si el valor no es vacío y no es null
        if ($request->has('pedido') && $request->pedido !== '' && $request->pedido !== null) {
            $query->where('pedido', $request->pedido);
        }
        $faltantes = $query->get()->map(fn($faltante) => [
            'id'          => $faltante->id,
            'descripcion' => $faltante->descripcion,
            'quantity'    => $faltante->quantity,
            'notes'       => $faltante->notes,
            'pedido'      => $faltante->pedido,
            'confirmado'  => $faltante->confirmado,
            'created_at'  => $faltante->created_at->format('H:i'),
            'fecha'       => $faltante->created_at->format('d/m/Y'),
            'user'        => $faltante->user->name,
        ])->values();

        // Stats calculadas en BD, no en colección
        $branchId            = $request->user_branch_id;
        $statsQuery          = Faltante::query();
        if (!($request->is_root ?? false) && $branchId) {
            $statsQuery->where('branch_id', $branchId);
        }
        $totalFaltantes      = (clone $statsQuery)->count();
        $faltantesPendientes = (clone $statsQuery)->where('confirmado', false)->count();
        $faltantesConfirmados = (clone $statsQuery)->where('confirmado', true)->count();

        // Productos con stock bajo — query directa sin cargar todo el catálogo
        $productosStockBajoQuery = \App\Models\BranchInventory::where('branch_id', $branchId)
            ->with('product')
            ->get()
            ->filter(fn($inv) => $inv->stock >= 0 && $inv->stock <= 2 && $inv->product)
            ->map(fn($inv) => [
                'id'    => $inv->product->id,
                'name'  => $inv->product->name,
                'stock' => $inv->stock,
                'price' => $inv->product->price,
            ])->values();

        $productosStockBajo      = $productosStockBajoQuery;
        $productosStockBajoCount = $productosStockBajo->count();

        return Inertia::render('Faltantes/Index', [
            'faltantes' => $faltantes,
            'productosStockBajo' => $productosStockBajo,
            'stats' => [
                'total' => $totalFaltantes,
                'pendientes' => $faltantesPendientes,
                'confirmados' => $faltantesConfirmados,
                'stockBajo' => $productosStockBajoCount,
            ],
            'filters' => [
                'confirmado' => $request->confirmado,
                'pedido' => $request->pedido,
            ],
            'faltantesManualesHabilitados' => SystemSetting::faltantesManualesHabilitados(),
        ]);
    }

    public function store(StoreFaltanteRequest $request)
    {
        Faltante::create([
            'descripcion' => $request->descripcion,
            'pedido'      => $request->pedido,
            'confirmado'  => $request->confirmado ?? 0,
            'user_id'     => Auth::id(),
            'branch_id'   => $request->user_branch_id,
        ]);
        return redirect()->back();
    }

    public function update(Request $request, Faltante $faltante)
    {
        $this->authorizeBranch($faltante->branch_id, $request);

        $request->validate([
            'descripcion' => 'required|string|max:255',
            'pedido'      => 'required|in:GENERAL,TRUPER',
            'confirmado'  => 'boolean',
        ]);
        $faltante->update([
            'descripcion' => $request->descripcion,
            'pedido'      => $request->pedido,
            'confirmado'  => $request->confirmado ?? $faltante->confirmado,
        ]);
        return redirect()->back();
    }

    public function confirmar(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:faltantes,id',
        ]);

        $branchId = $request->user_branch_id;
        $isRoot   = $request->is_root ?? false;

        // Solo confirmar faltantes que pertenezcan a la sucursal del usuario
        $query = Faltante::whereIn('id', $request->ids);
        if (!$isRoot) {
            $query->where('branch_id', $branchId);
        }
        $query->update(['confirmado' => 1]);

        return redirect()->back();
    }

    public function destroy(Faltante $faltante)
    {
        $this->authorizeBranch($faltante->branch_id, request());

        $faltante->delete();
        return redirect()->back();
    }

    private function authorizeBranch(?int $resourceBranchId, \Illuminate\Http\Request $request): void
    {
        $isRoot = $request->is_root ?? false;
        if (!$isRoot && $resourceBranchId !== $request->user_branch_id) {
            abort(403, 'No tienes acceso a datos de otra sucursal.');
        }
    }
}
