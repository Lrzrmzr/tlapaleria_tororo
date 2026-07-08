<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Faltante;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // branchId = null para root (ve todo), int para usuarios de sucursal
        $branchId = $request->user_branch_id;

        // ── Ventas (filtradas por sucursal, excluye canceladas) ────────────
        $ventasHoy  = Sale::completed()->whereDate('created_at', today())
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->sum('total');
        $ventasAyer = Sale::completed()->whereDate('created_at', today()->subDay())
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->sum('total');
        $cambioVentas = $ventasAyer == 0
            ? ($ventasHoy > 0 ? 100 : 0)
            : round((($ventasHoy - $ventasAyer) / $ventasAyer) * 100, 1);

        // ── Faltantes (filtrados por sucursal) ────────────────────────────
        $faltantesPendientes = Faltante::where('confirmado', false)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->count();
        $faltantesHoy  = Faltante::whereDate('created_at', today())
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->count();
        $faltantesAyer = Faltante::whereDate('created_at', today()->subDay())
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->count();
        $cambioFaltantes = $faltantesHoy - $faltantesAyer;

        // ── Productos y Clientes (catálogo global — sin branch_id) ────────
        $totalProductos  = Product::totalProductos();
        $totalClientes   = Customer::totalClientes();
        $cambioProductos = Product::productosNuevosHoy();
        $cambioClientes  = Customer::clientesNuevosHoy();

        $mainStats = [
            [
                'title' => 'Ventas Hoy',
                'value' => '$' . number_format($ventasHoy, 0),
                'icon' => '<svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>',
                'change' => ($cambioVentas >= 0 ? '+' : '') . $cambioVentas . '%',
                'positive' => $cambioVentas >= 0,
                'bgColor' => 'from-orange-500 to-orange-600',
                'lightBg' => 'bg-orange-50 dark:bg-orange-900/20'
            ],
            [
                'title' => 'Productos',
                'value' => number_format($totalProductos),
                'icon' => '<svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                      </svg>',
                'change' => '+' . $cambioProductos,
                'positive' => true,
                'bgColor' => 'from-blue-500 to-blue-600',
                'lightBg' => 'bg-blue-50 dark:bg-blue-900/20'
            ],
            [
                'title' => 'Clientes',
                'value' => number_format($totalClientes),
                'icon' => '<svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                      </svg>',
                'change' => '+' . $cambioClientes,
                'positive' => true,
                'bgColor' => 'from-purple-500 to-purple-600',
                'lightBg' => 'bg-purple-50 dark:bg-purple-900/20'
            ],
            [
                'title' => 'Faltantes',
                'value' => number_format($faltantesPendientes),
                'icon' => '<svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                      </svg>',
                'change' => ($cambioFaltantes >= 0 ? '+' : '') . $cambioFaltantes,
                'positive' => $cambioFaltantes < 0,
                'bgColor' => 'from-red-500 to-red-600',
                'lightBg' => 'bg-red-50 dark:bg-red-900/20'
            ]
        ];

        // ── Ventas recientes (filtradas por sucursal, excluye canceladas) ──
        $recentSales = Sale::completed()->with(['customer', 'user', 'saleDetails.product'])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->latest()
            ->take(4)
            ->get()
            ->map(function ($sale) {
                $firstDetail = $sale->saleDetails->first();
                return [
                    'product'  => $firstDetail ? $firstDetail->descripcion_completa : 'N/A',
                    'quantity' => $sale->saleDetails->sum('quantity'),
                    'amount'   => '$' . number_format($sale->total, 0),
                    'time'     => $sale->created_at->diffForHumans(),
                    'customer' => $sale->customer ? $sale->customer->name : 'Cliente general',
                ];
            });

        // ── Top productos (filtrados por sucursal, excluye canceladas) ─────
        $topProducts = Product::select('products.*')
            ->join('sale_details', 'products.id', '=', 'sale_details.product_id')
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->where('sale_details.tipo_venta', 'catalogo')
            ->where('sales.status', 'completed')
            ->when($branchId, fn($q) => $q->where('sales.branch_id', $branchId))
            ->groupBy('products.id')
            ->selectRaw('SUM(sale_details.quantity) as total_ventas')
            ->selectRaw('SUM(sale_details.subtotal) as total_ingresos')
            ->orderByDesc('total_ventas')
            ->take(4)
            ->get()
            ->map(function ($product) {
                return [
                    'name'    => $product->name,
                    'sales'   => $product->total_ventas,
                    'revenue' => '$' . number_format($product->total_ingresos, 0),
                    'trend'   => 'up',
                    'color'   => 'text-orange-600',
                ];
            });

        // Si no hay datos, usar datos de ejemplo
        if ($topProducts->isEmpty()) {
            $topProducts = collect([
                ['name' => 'Sin datos', 'sales' => 0, 'revenue' => '$0', 'trend' => 'up', 'color' => 'text-gray-600'],
            ]);
        }

        if ($recentSales->isEmpty()) {
            $recentSales = collect([
                ['product' => 'Sin ventas registradas', 'quantity' => 0, 'amount' => '$0', 'time' => 'N/A', 'customer' => 'N/A'],
            ]);
        }

        // Alertas
        $alerts = [];

        // Alerta de stock bajo — query directa, sin cargar todos los productos en memoria
        $stockCritico = SystemSetting::stockCriticoThreshold();
        try {
            $productosStockBajo = \App\Models\BranchInventory::selectRaw('product_id')
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->groupBy('product_id')
                ->havingRaw('SUM(stock) BETWEEN 0 AND ?', [$stockCritico])
                ->count();
        } catch (\Exception) {
            $productosStockBajo = 0;
        }

        if ($productosStockBajo > 0) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => '⚠️',
                'title' => 'Stock Bajo',
                'message' => $productosStockBajo . " productos con stock crítico (≤ {$stockCritico} unidades)",
                'color' => 'border-yellow-500 bg-yellow-50 dark:bg-yellow-900/20'
            ];
        }

        // Alerta de faltantes pendientes
        if ($faltantesPendientes > 0) {
            $alerts[] = [
                'type' => 'warning',
                'icon' => '📝',
                'title' => 'Faltantes Pendientes',
                'message' => $faltantesPendientes . ' productos faltantes por confirmar',
                'color' => 'border-orange-500 bg-orange-50 dark:bg-orange-900/20'
            ];
        }

        // Alerta de éxito
        $ventasMes = Sale::completed()->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->sum('total');
        if ($ventasMes > 50000) { // Meta de ejemplo
            $alerts[] = [
                'type' => 'success',
                'icon' => '✅',
                'title' => 'Meta Cumplida',
                'message' => 'Objetivo de ventas del mes alcanzado: $' . number_format($ventasMes, 0),
                'color' => 'border-green-500 bg-green-50 dark:bg-green-900/20'
            ];
        }

        // Si no hay alertas, mostrar una de info
        if (empty($alerts)) {
            $alerts[] = [
                'type' => 'info',
                'icon' => 'ℹ️',
                'title' => 'Sistema Operativo',
                'message' => 'Todo funcionando correctamente',
                'color' => 'border-blue-500 bg-blue-50 dark:bg-blue-900/20'
            ];
        }

        return Inertia::render('Dashboard', [
            'mainStats' => $mainStats,
            'recentSales' => $recentSales,
            'topProducts' => $topProducts,
            'alerts' => $alerts,
        ]);
    }
}
