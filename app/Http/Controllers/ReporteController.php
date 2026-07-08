<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Gasto;
use App\Models\Cobro;
use App\Models\PagoProveedor;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;

class ReporteController extends Controller
{
    public function index(Request $request)
    {
        $user     = Auth::user();
        $roleName = $user->roles->first()?->name ?? 'trabajador';
        $isRoot   = $request->is_root ?? false;
        $branches = $isRoot
            ? Branch::where('active', true)->orderBy('name')->get(['id', 'name'])
            : collect();

        return Inertia::render('Reportes/Index', [
            ...$this->buildData($request),
            'branches' => $branches,
            'user'     => [
                'id'     => $user->id,
                'name'   => $user->name,
                'role'   => $roleName,
                'isRoot' => $isRoot,
            ],
        ]);
    }

    public function exportPdf(Request $request)
    {
        $data = $this->buildData($request);
        $pdf  = \Barryvdh\DomPDF\Facade\Pdf::loadView('reportes.corte-pdf', $data)
            ->setPaper('letter', 'portrait');

        return $pdf->download('corte-' . $data['filters']['fecha_desde'] . '.pdf');
    }

    public function exportCsv(Request $request)
    {
        $data     = $this->buildData($request);
        $filename = 'reporte-' . $data['filters']['fecha_desde'] . '.csv';

        return response()->stream(function () use ($data) {
            $f     = fopen('php://output', 'w');
            $corte = $data['corte'];
            $f2    = $data['filters'];

            fputs($f, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel

            fputcsv($f, ['CORTE DE CAJA / REPORTE DE VENTAS']);
            fputcsv($f, ['Período', $f2['fecha_desde'] . ' al ' . $f2['fecha_hasta']]);
            fputcsv($f, ['Generado', now()->format('d/m/Y H:i')]);
            fputcsv($f, []);

            fputcsv($f, ['RESUMEN']);
            fputcsv($f, ['Concepto', 'Monto']);
            fputcsv($f, ['Total Ventas',          $corte['total_ventas']]);
            fputcsv($f, ['Utilidad Estimada',     $corte['total_utilidad']]);
            fputcsv($f, ['Cobros Recibidos',      $corte['total_cobros']]);
            fputcsv($f, ['Gastos del Período',    $corte['total_gastos']]);
            fputcsv($f, ['Pagos a Proveedores',   $corte['total_pagos']]);
            fputcsv($f, ['SALDO NETO',             $corte['saldo_neto']]);
            fputcsv($f, []);

            if (count($corte['ventas_por_tipo'])) {
                fputcsv($f, ['VENTAS POR TIPO']);
                fputcsv($f, ['Tipo', 'Transacciones', 'Artículos', 'Total']);
                foreach ($corte['ventas_por_tipo'] as $t) {
                    fputcsv($f, [
                        $t['tipo'] === 'libre' ? 'Venta Libre' : 'Catálogo',
                        $t['ventas'],
                        $t['cantidad'],
                        $t['total'],
                    ]);
                }
                fputcsv($f, []);
            }

            if (count($corte['top_productos'])) {
                fputcsv($f, ['TOP PRODUCTOS VENDIDOS']);
                fputcsv($f, ['#', 'Producto', 'Cantidad', 'Total']);
                foreach ($corte['top_productos'] as $i => $p) {
                    fputcsv($f, [$i + 1, $p['name'], $p['cantidad'], $p['total']]);
                }
                fputcsv($f, []);
            }

            if (count($corte['gastos_detalle'])) {
                fputcsv($f, ['DETALLE DE GASTOS']);
                fputcsv($f, ['Descripción', 'Monto', 'Registrado por', 'Fecha']);
                foreach ($corte['gastos_detalle'] as $g) {
                    fputcsv($f, [$g['descripcion'], $g['monto'], $g['usuario'] ?? '', $g['fecha']]);
                }
            }

            fclose($f);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function buildData(Request $request): array
    {
        $userBranchId = $request->user_branch_id;
        $isRoot       = $request->is_root ?? false;

        $fechaDesde = Carbon::parse($request->filled('fecha_desde') ? $request->fecha_desde : today())->startOfDay();
        $fechaHasta = Carbon::parse($request->filled('fecha_hasta') ? $request->fecha_hasta : today())->endOfDay();

        $branchId = ($isRoot && $request->filled('branch_id'))
            ? (int) $request->branch_id
            : $userBranchId;

        $fdStr = $fechaDesde->toDateString();
        $fhStr = $fechaHasta->toDateString();

        // ── VENTAS ─ filtradas por fecha_venta (fecha de negocio, no de captura) ─
        $ventasQ = Sale::whereBetween('fecha_venta', [$fdStr, $fhStr])->completed();
        if ($branchId) $ventasQ->where('branch_id', $branchId);

        $totalVentas = (float) $ventasQ->sum('total');
        $countVentas = (int)   $ventasQ->count();

        // Desglose libre / catálogo
        $ventasPorTipo = SaleDetail::whereHas('sale', function ($q) use ($fdStr, $fhStr, $branchId) {
            $q->whereBetween('fecha_venta', [$fdStr, $fhStr])->completed();
            if ($branchId) $q->where('branch_id', $branchId);
        })
        ->select(
            'tipo_venta',
            DB::raw('SUM(subtotal) as total'),
            DB::raw('COUNT(DISTINCT sale_id) as ventas'),
            DB::raw('SUM(quantity) as cantidad')
        )
        ->groupBy('tipo_venta')
        ->get()
        ->map(fn($r) => [
            'tipo'     => $r->tipo_venta,
            'total'    => (float) $r->total,
            'ventas'   => (int)   $r->ventas,
            'cantidad' => (int)   $r->cantidad,
        ]);

        // Top 10 productos por ingreso
        $topProductos = SaleDetail::whereHas('sale', function ($q) use ($fdStr, $fhStr, $branchId) {
            $q->whereBetween('fecha_venta', [$fdStr, $fhStr])->completed();
            if ($branchId) $q->where('branch_id', $branchId);
        })
        ->where('tipo_venta', 'catalogo')
        ->whereNotNull('product_id')
        ->join('products', 'sale_details.product_id', '=', 'products.id')
        ->select(
            'products.name',
            DB::raw('SUM(sale_details.quantity) as cantidad'),
            DB::raw('SUM(sale_details.subtotal) as total')
        )
        ->groupBy('products.id', 'products.name')
        ->orderByDesc('total')
        ->limit(10)
        ->get()
        ->map(fn($r) => ['name' => $r->name, 'cantidad' => (int) $r->cantidad, 'total' => (float) $r->total]);

        // Utilidad: (precio - costo) * cantidad, solo líneas con costo conocido
        $totalUtilidad = (float) SaleDetail::whereHas('sale', function ($q) use ($fdStr, $fhStr, $branchId) {
            $q->whereBetween('fecha_venta', [$fdStr, $fhStr])->completed();
            if ($branchId) $q->where('branch_id', $branchId);
        })
        ->whereNotNull('cost')
        ->selectRaw('SUM((price - cost) * quantity) as utilidad')
        ->value('utilidad') ?? 0;

        // ── GASTOS ─ filtrados por fecha_gasto (fecha de negocio) ────────────
        $gastosQ = Gasto::whereBetween('fecha_gasto', [$fdStr, $fhStr]);
        if ($branchId) $gastosQ->where('branch_id', $branchId);

        $totalGastos   = (float) $gastosQ->sum('monto');
        $gastosDetalle = $gastosQ->with('user')->orderBy('fecha_gasto')->get()->map(fn($g) => [
            'descripcion' => $g->descripcion,
            'monto'       => (float) $g->monto,
            'usuario'     => $g->user?->name,
            'fecha'       => optional($g->fecha_gasto)->format('d/m/Y') ?? $g->created_at->format('d/m/Y'),
        ]);

        // ── COBROS de clientes ────────────────────────────────────────────────
        $totalCobros = (float) Cobro::whereBetween('fecha_cobro', [$fechaDesde->toDateString(), $fechaHasta->toDateString()])
            ->whereHas('cuentaPorCobrar', function ($q) use ($branchId) {
                if ($branchId) $q->where('branch_id', $branchId);
            })
            ->sum('monto');

        // ── PAGOS a proveedores ───────────────────────────────────────────────
        $totalPagos = (float) PagoProveedor::whereBetween('fecha_pago', [$fechaDesde->toDateString(), $fechaHasta->toDateString()])
            ->whereHas('cuentaPorPagar', function ($q) use ($branchId) {
                if ($branchId) $q->where('branch_id', $branchId);
            })
            ->sum('monto');

        $saldoNeto = $totalVentas + $totalCobros - $totalGastos - $totalPagos;

        return [
            'corte' => [
                'total_ventas'    => $totalVentas,
                'count_ventas'    => $countVentas,
                'total_utilidad'  => $totalUtilidad,
                'total_gastos'    => $totalGastos,
                'total_cobros'    => $totalCobros,
                'total_pagos'     => $totalPagos,
                'saldo_neto'      => $saldoNeto,
                'ventas_por_tipo' => $ventasPorTipo,
                'top_productos'   => $topProductos,
                'gastos_detalle'  => $gastosDetalle,
            ],
            'filters' => [
                'fecha_desde' => $fechaDesde->toDateString(),
                'fecha_hasta' => $fechaHasta->toDateString(),
                'branch_id'   => $branchId,
            ],
        ];
    }
}
