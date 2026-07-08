<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class InventarioApiController extends Controller
{
    /**
     * Listar inventario con filtros
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = BranchInventory::with(['product.supplier', 'branch']);

        // Si no es root, solo ve inventario de su sucursal
        if (!$user->isRoot() && $user->branch_id) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        // Filtro: Búsqueda por nombre del producto
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        // Filtro: Por estado de stock
        if ($request->filled('stock_status')) {
            $status = $request->stock_status;
            $stockCritico = SystemSetting::stockCriticoThreshold();
            if ($status === 'agotado') {
                $query->where('stock', 0);
            } elseif ($status === 'critico') {
                $query->where('stock', '>', 0)->where('stock', '<=', $stockCritico);
            } elseif ($status === 'bajo') {
                $query->whereColumn('stock', '<=', 'min_stock')->where('stock', '>', $stockCritico);
            }
        }

        $perPage     = min(max((int) $request->get('per_page', 50), 1), 200);
        $inventarios = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $inventarios->items(),
            'pagination' => [
                'total' => $inventarios->total(),
                'per_page' => $inventarios->perPage(),
                'current_page' => $inventarios->currentPage(),
                'last_page' => $inventarios->lastPage(),
            ]
        ]);
    }

    /**
     * Obtener inventario de un producto específico
     */
    public function show(Request $request, int $id)
    {
        $inventario = BranchInventory::with(['product.supplier', 'branch'])
            ->findOrFail($id);

        $user = $request->user();
        if (!$user->isRoot() && $inventario->branch_id !== $user->branch_id) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso al inventario de otra sucursal.'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $inventario
        ]);
    }

    /**
     * Ajustar stock de inventario
     */
    public function adjustStock(Request $request, int $id)
    {
        $validated = $request->validate([
            'adjustment' => 'required|integer',
            'reason' => 'nullable|string',
        ]);

        $inventario = BranchInventory::findOrFail($id);

        $user = $request->user();
        if (!$user->isRoot() && $inventario->branch_id !== $user->branch_id) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso al inventario de otra sucursal.'
            ], 403);
        }

        $oldStock = $inventario->stock;

        try {
            InventoryMovement::aplicar(
                $inventario,
                'ajuste',
                $validated['adjustment'],
                $validated['reason'] ?? 'Ajuste desde app móvil',
                null,
                $user->id
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => 'El stock no puede ser negativo'
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Stock ajustado exitosamente',
            'data' => [
                'old_stock' => $oldStock,
                'adjustment' => $validated['adjustment'],
                'new_stock' => $inventario->stock,
                'product' => $inventario->product->name,
            ]
        ]);
    }

    /**
     * Crear nuevo inventario para un producto
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'branch_id' => 'required|exists:branches,id',
            'stock' => 'required|integer|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
        ]);

        // Non-root users can only create inventory for their own branch
        if (!$user->isRoot() && (int) $validated['branch_id'] !== (int) $user->branch_id) {
            return response()->json([
                'success' => false,
                'message' => 'Solo puedes crear inventario para tu propia sucursal.'
            ], 403);
        }

        // Verificar que no exista ya
        $exists = BranchInventory::where('product_id', $validated['product_id'])
            ->where('branch_id', $validated['branch_id'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'El inventario para este producto ya existe en esta sucursal'
            ], 400);
        }

        $inventario = BranchInventory::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Inventario creado exitosamente',
            'data' => $inventario->load(['product', 'branch'])
        ], 201);
    }

    /**
     * Productos sin inventario
     */
    public function productosSinInventario(Request $request)
    {
        $user = $request->user();

        // Root puede consultar cualquier sucursal; no-root solo la suya
        if ($user->isRoot()) {
            $branchId = $request->get('branch_id');
        } else {
            $branchId = $user->branch_id;
        }

        if (!$branchId) {
            return response()->json([
                'success' => false,
                'message' => 'Se requiere un branch_id'
            ], 400);
        }

        $productos = Product::whereDoesntHave('branchInventory', function ($q) use ($branchId) {
            $q->where('branch_id', $branchId);
        })->get();

        return response()->json([
            'success' => true,
            'data' => $productos
        ]);
    }
}
