<?php

namespace App\Http\Controllers;

use App\Models\BranchInventory;
use App\Models\Product;
use App\Models\Branch;
use App\Models\SystemSetting;
use App\Services\InventarioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use RuntimeException;

class InventarioController extends Controller
{
    public function __construct(private InventarioService $inventarioService)
    {
    }

    public function index(Request $request)
    {
        $userBranchId = $request->user_branch_id; // Inyectado por middleware
        $isRoot = $request->is_root ?? false; // Inyectado por middleware

        // Si es root, obtener todas las sucursales, si no, solo la del usuario
        $branches = $isRoot
            ? Branch::where('active', true)->orderBy('name')->get()
            : Branch::where('id', $userBranchId)->get();

        // Obtener inventarios por sucursal con filtros
        $query = BranchInventory::with(['product', 'branch']);

        // Si no es root, filtrar solo por su sucursal
        if (!$isRoot) {
            $query->where('branch_id', $userBranchId);
        } else {
            // Si es root y especifica sucursal en filtro
            if ($request->filled('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }
        }

        // Filtro: Búsqueda por nombre del producto
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        // Filtro: Por estado de stock
        $stockCritico = SystemSetting::stockCriticoThreshold();
        if ($request->filled('stock_status')) {
            $status = $request->stock_status;
            if ($status === 'agotado') {
                $query->where('stock', 0);
            } elseif ($status === 'critico') {
                $query->where('stock', '>', 0)->where('stock', '<=', $stockCritico);
            } elseif ($status === 'bajo') {
                $query->whereColumn('stock', '<=', 'min_stock')
                      ->where('stock', '>', $stockCritico);
            } elseif ($status === 'sobre') {
                $query->where('max_stock', '>', 0)
                      ->whereColumn('stock', '>', 'max_stock');
            }
        }

        // Ordenamiento — whitelist para prevenir SQL injection
        $allowedSorts  = ['updated_at', 'created_at', 'stock', 'min_stock', 'cost', 'product_name'];
        $allowedOrders = ['asc', 'desc'];
        $sortBy    = in_array($request->sort_by, $allowedSorts, true)    ? $request->sort_by    : 'updated_at';
        $sortOrder = in_array($request->sort_order, $allowedOrders, true) ? $request->sort_order : 'desc';

        // Paginación — límite máximo para prevenir DoS
        $perPage = min(max((int) $request->get('per_page', 50), 1), 200);

        if ($sortBy === 'product_name') {
            // Ordenar por nombre de producto requiere un join
            $query->join('products', 'branch_inventory.product_id', '=', 'products.id')
                  ->orderBy('products.name', $sortOrder)
                  ->select('branch_inventory.*');
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        $inventarios = $query->paginate($perPage)->through(function ($inventario) {
            return [
                'id' => $inventario->id,
                'product_id' => $inventario->product_id,
                'product_name' => $inventario->product->name ?? 'Producto eliminado',
                'product_code' => $inventario->product->barcode ?? '',
                'product_price' => $inventario->product->price ?? 0,
                'branch_id' => $inventario->branch_id,
                'branch_name' => $inventario->branch ? $inventario->branch->name : '⚠️ Sin Sucursal',
                'branch_code' => $inventario->branch->code ?? '',
                'quantity' => $inventario->stock ?? 0,
                'min_stock' => $inventario->min_stock ?? 0,
                'max_stock' => $inventario->max_stock ?? 0,
                'cost' => $inventario->cost ?? 0,
                'status' => $this->getStockStatus($inventario->stock ?? 0, $inventario->min_stock ?? 0, $inventario->max_stock ?? 0),
                'sobre_stock' => ($inventario->max_stock ?? 0) > 0 && ($inventario->stock ?? 0) > $inventario->max_stock,
                'updated_at' => $inventario->updated_at->format('d/m/Y H:i'),
            ];
        });

        // Productos sin inventario en esta sucursal (o en general si es root)
        $productosQuery = Product::where('active', true);

        if (!$isRoot) {
            // Productos que no tienen inventario en la sucursal del usuario
            $productosQuery->whereDoesntHave('branchInventory', function($q) use ($userBranchId) {
                $q->where('branch_id', $userBranchId);
            });
        } else {
            // Para root, productos que no tienen inventario en la sucursal especificada o ninguna
            if ($request->filled('branch_id')) {
                $branchId = $request->branch_id;
                $productosQuery->whereDoesntHave('branchInventory', function($q) use ($branchId) {
                    $q->where('branch_id', $branchId);
                });
            } else {
                $productosQuery->whereDoesntHave('branchInventory');
            }
        }

        $productosSinInventario = $productosQuery->get()->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'code' => $product->barcode,
                'price' => $product->price,
            ];
        });

        // Estadísticas
        $totalProductos = Product::where('active', true)->count();

        if (!$isRoot) {
            $statsScope = fn() => BranchInventory::where('branch_id', $userBranchId);
        } elseif ($request->filled('branch_id')) {
            $branchId = $request->branch_id;
            $statsScope = fn() => BranchInventory::where('branch_id', $branchId);
        } else {
            $statsScope = fn() => BranchInventory::query();
        }

        $productosConInventario = $statsScope()->count();
        $productosStockBajo = $statsScope()
            ->whereColumn('stock', '<=', 'min_stock')
            ->where('stock', '>', 0)
            ->count();
        $productosAgotados = $statsScope()->where('stock', 0)->count();
        $productosSobreStock = $statsScope()
            ->where('max_stock', '>', 0)
            ->whereColumn('stock', '>', 'max_stock')
            ->count();

        return Inertia::render('Inventario/Index', [
            'inventarios' => $inventarios,
            'productosSinInventario' => $productosSinInventario,
            'branches' => $branches,
            'userBranch' => $isRoot ? null : $branches->first(),
            'isRoot' => $isRoot,
            'filters' => [
                'search' => $request->search,
                'branch_id' => $request->branch_id,
                'stock_status' => $request->stock_status,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
            'stats' => [
                'total' => $totalProductos,
                'conInventario' => $productosConInventario,
                'stockBajo' => $productosStockBajo,
                'agotados' => $productosAgotados,
                'sobreStock' => $productosSobreStock,
            ]
        ]);
    }

    public function store(Request $request)
    {
        $userBranchId = $request->user_branch_id;
        $isRoot = $request->is_root ?? false;

        $request->validate([
            'product_id' => 'required|exists:products,id',
            'branch_id' => $isRoot ? 'required|exists:branches,id' : 'nullable',
            'quantity' => 'required|integer|min:0',
            'min_stock' => 'required|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'cost' => 'nullable|numeric|min:0',
        ]);

        $branchId = $isRoot ? $request->branch_id : $userBranchId;

        $this->authorize('create', [BranchInventory::class, (int) $branchId]);

        try {
            $this->inventarioService->crear([
                'branch_id'  => $branchId,
                'product_id' => $request->product_id,
                'quantity'   => $request->quantity,
                'min_stock'  => $request->min_stock,
                'max_stock'  => $request->max_stock,
                'cost'       => $request->cost,
            ]);
        } catch (RuntimeException $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Inventario creado correctamente');
    }

    public function update(Request $request, BranchInventory $inventario)
    {
        $this->authorize('update', $inventario);

        $request->validate([
            'quantity'  => 'required|integer|min:0',
            'min_stock' => 'required|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'cost'      => 'nullable|numeric|min:0',
        ]);

        $this->inventarioService->actualizar($inventario, [
            'quantity'  => $request->quantity,
            'min_stock' => $request->min_stock,
            'max_stock' => $request->max_stock,
            'cost'      => $request->cost,
        ], Auth::id());

        return redirect()->back()->with('success', 'Inventario actualizado correctamente');
    }

    public function ajustarStock(Request $request, BranchInventory $inventario)
    {
        $this->authorize('update', $inventario);

        $request->validate([
            'cantidad' => 'required|integer|min:1',
            'tipo'     => 'required|in:entrada,salida',
            'motivo'   => 'nullable|string|max:255',
        ]);

        try {
            $this->inventarioService->ajustarStock($inventario, $request->cantidad, $request->tipo, $request->motivo, Auth::id());
        } catch (RuntimeException $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Stock ajustado correctamente');
    }

    public function destroy(BranchInventory $inventario)
    {
        $this->authorize('delete', $inventario);

        $inventario->delete();
        return redirect()->back()->with('success', 'Inventario eliminado correctamente');
    }

    private function getStockStatus($quantity, $minStock, $maxStock = 0)
    {
        if ($quantity === 0) {
            return 'agotado';
        } elseif ($quantity <= SystemSetting::stockCriticoThreshold()) {
            return 'critico';
        } elseif ($quantity <= $minStock) {
            return 'bajo';
        } elseif ($maxStock > 0 && $quantity > $maxStock) {
            return 'sobre';
        }
        return 'normal';
    }
}
