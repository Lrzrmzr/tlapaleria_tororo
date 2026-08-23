<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductApiController extends Controller
{
    /**
     * Listar productos con filtros y paginación
     */
    public function index(Request $request)
    {
        $query = Product::with(['supplier', 'categories'])
            ->with(['branchInventory' => $this->branchInventoryScope($request)]);

        // Filtro: Búsqueda por nombre, descripción o código de barras
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        // Filtro: Por proveedor
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        // Filtro: Por categoría
        if ($request->filled('category_id')) {
            $query->whereHas('categories', function ($q) use ($request) {
                $q->where('categories.id', $request->category_id);
            });
        }

        // Filtro: Solo activos
        if ($request->filled('active')) {
            $query->where('active', $request->active);
        }

        $perPage  = min(max($request->integer('per_page', 50), 1), 200);
        $products = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => ProductResource::collection($products->items()),
            'pagination' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'from' => $products->firstItem(),
                'to' => $products->lastItem(),
            ]
        ]);
    }

    /**
     * Obtener un producto específico
     */
    public function show(Request $request, int $id)
    {
        $product = Product::with(['supplier', 'categories'])
            ->with(['branchInventory' => $this->branchInventoryScope($request)])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
        ]);
    }

    /**
     * Crear un nuevo producto
     */
    public function store(Request $request)
    {
        $this->authorize('create', Product::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'barcode' => 'nullable|string|unique:products,barcode',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'active' => 'boolean',
        ]);

        $product = Product::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Producto creado exitosamente',
            'data' => new ProductResource($product->load(['supplier', 'categories']))
        ], 201);
    }

    /**
     * Actualizar un producto
     */
    public function update(Request $request, int $id)
    {
        $product = Product::findOrFail($id);
        $this->authorize('update', $product);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'barcode' => 'nullable|string|unique:products,barcode,' . $id,
            'price' => 'sometimes|required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'active' => 'boolean',
        ]);

        $product->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Producto actualizado exitosamente',
            'data' => new ProductResource($product->load(['supplier', 'categories']))
        ]);
    }

    /**
     * Eliminar un producto
     */
    public function destroy(Request $request, int $id)
    {
        $product = Product::findOrFail($id);
        $this->authorize('delete', $product);

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Producto eliminado exitosamente'
        ]);
    }

    /**
     * Buscar productos por código de barras
     */
    public function searchByBarcode(Request $request)
    {
        $request->validate([
            'barcode' => 'required|string'
        ]);

        $product = Product::with(['supplier', 'categories'])
            ->with(['branchInventory' => $this->branchInventoryScope($request)])
            ->where('barcode', $request->barcode)
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new ProductResource($product),
        ]);
    }

    /**
     * Closure de scope para la relación branchInventory: root sin filtro ve
     * todas las sucursales (intencional); cualquier otro caso — incluido un
     * usuario no-root sin sucursal asignada, un estado de cuenta anómalo que
     * la API no puede descartar como sí hace check.branch en la web — se
     * limita a una sucursal concreta o, si no hay ninguna, no ve ningún
     * inventario (fail-closed) en vez de caer por defecto a "todas".
     */
    private function branchInventoryScope(Request $request): \Closure
    {
        $user = $request->user();

        if ($user->isRoot()) {
            $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;
            return $branchId
                ? fn ($q) => $q->where('branch_id', $branchId)
                : fn ($q) => $q->with('branch');
        }

        return fn ($q) => $q->where('branch_id', $user->branch_id);
    }
}
