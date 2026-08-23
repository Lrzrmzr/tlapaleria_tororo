<?php
namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\Product;
use App\Models\SystemSetting;
use App\Services\VentaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Carbon\Carbon;
use RuntimeException;

class VentaController extends Controller
{
    public function __construct(private VentaService $ventaService)
    {
    }

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
            'isRoot'                  => $isRoot,
            // Root no tiene sucursal propia: debe elegir una para registrar
            // ventas en efectivo (VentaService las exige con sucursal resuelta).
            'branches'                => $isRoot ? Branch::where('active', true)->orderBy('name')->get(['id', 'name']) : collect(),
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
        if ($request->has('items')) {
            return $this->storeCatalogoVenta($request);
        }

        return $this->storeLibreVenta($request);
    }

    private function storeLibreVenta(Request $request)
    {
        $isRoot = $request->is_root ?? false;

        $data = $request->validate([
            'descripcion'      => 'required|string|max:255',
            'total'            => 'required|numeric|min:0.01',
            'metodo_pago'      => 'nullable|in:efectivo,tarjeta,transferencia',
            'fecha_venta'      => 'nullable|date',
            'idempotency_key'  => 'nullable|string|max:64',
            'branch_id'        => $isRoot ? 'nullable|exists:branches,id' : 'nullable',
        ]);

        // Sin sucursal propia, root debe elegir una explícitamente (selector en Ventas/Index.vue).
        $branchId = $isRoot ? ($data['branch_id'] ?? null) : $request->user_branch_id;

        try {
            $this->ventaService->registrarLibre($data, $branchId, Auth::id());
        } catch (\Throwable $e) {
            return $this->friendlyError($e);
        }

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

        try {
            $this->ventaService->registrarCatalogo(
                $validated['items'],
                $validated['metodo_pago'] ?? null,
                $validated['fecha_venta'] ?? null,
                $validated['idempotency_key'] ?? null,
                $request->user_branch_id,
                Auth::id()
            );
        } catch (\Throwable $e) {
            return $this->friendlyError($e);
        }

        return redirect()->back()->with('success', 'Venta registrada correctamente. Inventario actualizado.');
    }

    /**
     * Cancela una venta ya registrada y devuelve el stock vendido (solo artículos de catálogo).
     */
    public function cancelar(Request $request, Sale $venta)
    {
        $this->authorize('cancelar', $venta);

        $request->validate([
            'motivo' => 'required|string|max:255',
        ]);

        try {
            $this->ventaService->cancelar($venta, $request->motivo, Auth::id());
        } catch (\Throwable $e) {
            return $this->friendlyError($e);
        }

        return redirect()->back()->with('success', 'Venta cancelada. El inventario fue restituido.');
    }

    /**
     * Errores de negocio esperados (RuntimeException) muestran su mensaje tal cual;
     * cualquier otra falla se reporta y muestra un mensaje genérico — el checkout
     * es el flujo de mayor tráfico del sistema, no debe romper con un 500 crudo.
     */
    private function friendlyError(\Throwable $e)
    {
        if ($e instanceof RuntimeException) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        report($e);
        return redirect()->back()->withErrors(['error' => 'Ocurrió un error inesperado al procesar la venta. Intenta de nuevo.']);
    }
}
