<?php
namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Product;
use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;

class VentaController extends Controller
{
    public function index(Request $request)
    {
        $branchId   = $request->user_branch_id;
        $isRoot     = $request->is_root ?? false;
        $fechaDesde = $request->fecha_desde ?? Carbon::today()->toDateString();
        $fechaHasta = $request->fecha_hasta ?? Carbon::today()->toDateString();
        $search     = $request->search ?? '';

        $query = Sale::with(['saleDetails.product', 'customer', 'user'])
            ->whereBetween('fecha_venta', [$fechaDesde, $fechaHasta]);

        if ($branchId && !$isRoot) {
            $query->where('branch_id', $branchId);
        }

        if ($search) {
            $query->whereHas('customer', fn($q) => $q->where('name', 'ilike', '%' . $search . '%'));
        }

        $ventas = $query->latest()->get()->map(function ($sale) {
            $utilidad = $sale->saleDetails->every(fn($d) => $d->cost !== null)
                ? $sale->saleDetails->sum(fn($d) => $d->utilidad)
                : null;

            return [
                'id'            => $sale->id,
                'total'         => $sale->total,
                'customer'      => $sale->customer ? $sale->customer->name : 'Cliente general',
                'user'          => $sale->user->name,
                'metodo_pago'   => $sale->metodo_pago,
                'status'        => $sale->status,
                'cancel_reason' => $sale->cancel_reason,
                'utilidad'      => $utilidad,
                'created_at'    => $sale->created_at->format('H:i'),
                'fecha'         => optional($sale->fecha_venta)->format('d/m/Y') ?? $sale->created_at->format('d/m/Y'),
                'details'       => $sale->saleDetails->map(function ($detail) {
                    return [
                        'tipo_venta'  => $detail->tipo_venta,
                        'descripcion' => $detail->tipo_venta === 'libre'
                            ? $detail->descripcion
                            : ($detail->product ? $detail->product->name : 'N/A'),
                        'quantity'    => $detail->quantity,
                        'price'       => $detail->price,
                        'subtotal'    => $detail->subtotal,
                    ];
                }),
            ];
        });

        $productos = Product::with([
                'branchInventory' => fn($q) => $branchId ? $q->where('branch_id', $branchId) : $q,
            ])
            ->where('active', true)
            ->get()
            ->map(function ($product) use ($branchId) {
                $stock = $branchId
                    ? ($product->branchInventory->first()?->stock ?? 0)
                    : $product->branchInventory->sum('stock');
                return [
                    'id'      => $product->id,
                    'name'    => $product->name,
                    'barcode' => $product->barcode,
                    'price'   => $product->price,
                    'stock'   => $stock,
                ];
            });

        $user = Auth::user();

        return Inertia::render('Ventas/Index', [
            'ventas'                  => $ventas,
            'productos'               => $productos,
            'user'                    => $user,
            'ventasLibresHabilitadas' => SystemSetting::ventasLibresHabilitadas(),
            'filters'                 => [
                'fecha_desde' => $fechaDesde,
                'fecha_hasta' => $fechaHasta,
                'search'      => $search,
            ],
        ]);
    }

    public function store(Request $request)
    {
        // Detectar tipo de venta
        if ($request->has('items')) {
            return $this->storeCatalogoVenta($request);
        } else {
            return $this->storeLibreVenta($request);
        }
    }

    private function storeLibreVenta(Request $request)
    {
        if (!SystemSetting::ventasLibresHabilitadas()) {
            return redirect()->back()->withErrors(['error' => 'Las ventas libres están deshabilitadas.']);
        }

        $request->validate([
            'descripcion'      => 'required|string|max:255',
            'total'            => 'required|numeric|min:0.01',
            'metodo_pago'      => 'nullable|in:efectivo,tarjeta,transferencia',
            'fecha_venta'      => 'nullable|date',
            'idempotency_key'  => 'nullable|string|max:64',
        ]);

        // Idempotencia: si ya existe una venta con esta clave, devolver sin duplicar
        if ($request->idempotency_key) {
            $existing = Sale::where('idempotency_key', $request->idempotency_key)->first();
            if ($existing) {
                return redirect()->back()->with('success', 'Venta ya registrada.');
            }
        }

        $totalVenta = $request->total;

        $venta = Sale::create([
            'user_id'          => Auth::id(),
            'branch_id'        => $request->user_branch_id,
            'customer_id'      => null,
            'total'            => $totalVenta,
            'status'           => 'completed',
            'metodo_pago'      => $request->metodo_pago ?? 'efectivo',
            'fecha_venta'      => $request->fecha_venta ?? now()->toDateString(),
            'idempotency_key'  => $request->idempotency_key,
        ]);

        // Crear el detalle de venta LIBRE (sin producto, sin costo conocido)
        SaleDetail::create([
            'sale_id' => $venta->id,
            'product_id' => null,
            'tipo_venta' => 'libre',
            'descripcion' => $request->descripcion,
            'quantity' => 1,
            'price' => $request->total,
            'cost' => null,
            'subtotal' => $request->total,
            'total' => $request->total
        ]);

        return redirect()->back()->with('success', 'Venta libre registrada correctamente');
    }

    private function storeCatalogoVenta(Request $request)
    {
        $validated = $request->validate([
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|exists:products,id',
            'items.*.quantity'       => 'required|integer|min:1',
            'metodo_pago'             => 'nullable|in:efectivo,tarjeta,transferencia',
            'fecha_venta'             => 'nullable|date',
            'idempotency_key'        => 'nullable|string|max:64',
        ]);

        // Idempotencia: si ya existe una venta con esta clave, devolver sin duplicar
        if (!empty($validated['idempotency_key'])) {
            $existing = Sale::where('idempotency_key', $validated['idempotency_key'])->first();
            if ($existing) {
                return redirect()->back()->with('success', 'Venta ya registrada.');
            }
        }

        $branchId = $request->user_branch_id;

        DB::beginTransaction();

        try {
            // Pre-cargar todos los productos e inventarios en bulk (evita N+1)
            $productIds = collect($validated['items'])->pluck('product_id')->unique();
            $products   = Product::whereIn('id', $productIds)->get()->keyBy('id');

            // Root user (branch_id=null): buscar el inventario con más stock por producto
            $invQuery = BranchInventory::whereIn('product_id', $productIds);
            if ($branchId) {
                $invQuery->where('branch_id', $branchId);
            } else {
                $invQuery->orderByDesc('stock');
            }
            // unique('product_id') conserva el primer registro por producto (el de mayor stock para root)
            $inventories = $invQuery->get()->unique('product_id')->keyBy('product_id');

            // Determinar la sucursal efectiva para la venta
            $effectiveBranchId = $branchId ?? $inventories->first()?->branch_id;

            // Validar stock y calcular total con precios del servidor
            $totalVenta = 0;
            foreach ($validated['items'] as $item) {
                $product   = $products->get($item['product_id']);
                $inventory = $inventories->get($item['product_id']);

                if (!$inventory) {
                    throw new \Exception("El producto {$product->name} no tiene inventario en esta sucursal.");
                }
                if ($inventory->stock < $item['quantity']) {
                    throw new \Exception("Stock insuficiente para {$product->name}. Disponible: {$inventory->stock}");
                }

                // Precio siempre del servidor, nunca del cliente
                $totalVenta += $product->price * $item['quantity'];
            }

            $venta = Sale::create([
                'user_id'          => Auth::id(),
                'branch_id'        => $effectiveBranchId,
                'customer_id'      => null,
                'total'            => $totalVenta,
                'status'           => 'completed',
                'metodo_pago'      => $validated['metodo_pago'] ?? 'efectivo',
                'fecha_venta'      => $validated['fecha_venta'] ?? now()->toDateString(),
                'idempotency_key'  => $validated['idempotency_key'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $product   = $products->get($item['product_id']);
                $inventory = $inventories->get($item['product_id']);
                $subtotal  = $product->price * $item['quantity'];

                SaleDetail::create([
                    'sale_id'     => $venta->id,
                    'product_id'  => $product->id,
                    'tipo_venta'  => 'catalogo',
                    'descripcion' => $product->name,
                    'quantity'    => $item['quantity'],
                    'price'       => $product->price,
                    'cost'        => $inventory->cost, // costo al momento de la venta, para calcular utilidad
                    'subtotal'    => $subtotal,
                    'total'       => $subtotal,
                ]);

                // Salida de inventario atómica + registro en el kardex
                InventoryMovement::aplicar(
                    $inventory,
                    'salida',
                    -$item['quantity'],
                    'Venta #' . $venta->id,
                    $venta,
                    Auth::id()
                );
            }

            DB::commit();
            return redirect()->back()->with('success', 'Venta registrada correctamente. Inventario actualizado.');

        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Cancela una venta ya registrada y devuelve el stock vendido (solo artículos de catálogo).
     */
    public function cancelar(Request $request, Sale $venta)
    {
        $isRoot   = $request->is_root ?? false;
        $branchId = $request->user_branch_id;

        if (!$isRoot && $venta->branch_id !== $branchId) {
            abort(403, 'No tienes acceso a ventas de otra sucursal.');
        }

        if ($venta->status === 'cancelled') {
            return redirect()->back()->withErrors(['error' => 'Esta venta ya está cancelada.']);
        }

        $request->validate([
            'motivo' => 'required|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            foreach ($venta->saleDetails as $detail) {
                if ($detail->tipo_venta !== 'catalogo' || !$detail->product_id) {
                    continue;
                }

                $inventory = BranchInventory::where('branch_id', $venta->branch_id)
                    ->where('product_id', $detail->product_id)
                    ->first();

                if ($inventory) {
                    InventoryMovement::aplicar(
                        $inventory,
                        'devolucion',
                        $detail->quantity,
                        'Cancelación de venta #' . $venta->id,
                        $venta,
                        Auth::id()
                    );
                }
            }

            $venta->update([
                'status'        => 'cancelled',
                'cancelled_by'  => Auth::id(),
                'cancelled_at'  => now(),
                'cancel_reason' => $request->motivo,
            ]);

            DB::commit();
            return redirect()->back()->with('success', 'Venta cancelada. El inventario fue restituido.');

        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => 'Error al cancelar la venta: ' . $e->getMessage()]);
        }
    }
}
