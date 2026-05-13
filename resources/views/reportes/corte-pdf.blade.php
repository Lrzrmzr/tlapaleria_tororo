<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Corte de Caja</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; color: #222; padding: 24px; }

        h1  { font-size: 20px; color: #1e3a5f; border-bottom: 2px solid #2563eb; padding-bottom: 6px; margin-bottom: 4px; }
        h2  { font-size: 12px; font-weight: bold; color: #1e3a5f; margin: 18px 0 6px;
              border-left: 3px solid #2563eb; padding-left: 7px; text-transform: uppercase; letter-spacing: .5px; }
        .meta { color: #666; font-size: 10px; margin-bottom: 18px; }

        /* ── Tarjetas de resumen ── */
        .cards { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .cards td { width: 20%; padding: 10px 6px; text-align: center; border: 1px solid #e2e8f0; }
        .card-val   { font-size: 15px; font-weight: bold; }
        .card-label { font-size: 9px; color: #64748b; margin-top: 2px; }
        .green .card-val { color: #16a34a; }
        .red   .card-val { color: #dc2626; }

        /* ── Saldo neto ── */
        .saldo { padding: 8px 12px; font-size: 14px; font-weight: bold; margin-bottom: 10px; }
        .saldo.pos { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .saldo.neg { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

        /* ── Tablas ── */
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        thead th { background: #1e3a5f; color: #fff; padding: 5px 7px; text-align: left; font-size: 10px; }
        tbody td { padding: 4px 7px; border-bottom: 1px solid #e2e8f0; }
        tbody tr:nth-child(even) td { background: #f8fafc; }
        .right { text-align: right; }
        .center { text-align: center; }
        .num { font-variant-numeric: tabular-nums; }
        .total-row td { font-weight: bold; background: #eff6ff !important; }
    </style>
</head>
<body>

<h1>Corte de Caja &mdash; Reporte de Ventas</h1>
<div class="meta">
    Período: <strong>{{ $filters['fecha_desde'] }}</strong> al <strong>{{ $filters['fecha_hasta'] }}</strong>
    &nbsp;&nbsp;|&nbsp;&nbsp; Generado: {{ now()->format('d/m/Y H:i') }}
</div>

{{-- ── TARJETAS ── --}}
<table class="cards">
    <tr>
        <td class="green">
            <div class="card-val">${{ number_format($corte['total_ventas'], 2) }}</div>
            <div class="card-label">Ventas ({{ $corte['count_ventas'] }})</div>
        </td>
        <td class="green">
            <div class="card-val">${{ number_format($corte['total_cobros'], 2) }}</div>
            <div class="card-label">Cobros Recibidos</div>
        </td>
        <td class="red">
            <div class="card-val">${{ number_format($corte['total_gastos'], 2) }}</div>
            <div class="card-label">Gastos</div>
        </td>
        <td class="red">
            <div class="card-val">${{ number_format($corte['total_pagos'], 2) }}</div>
            <div class="card-label">Pagos Proveedores</div>
        </td>
        <td class="{{ $corte['saldo_neto'] >= 0 ? 'green' : 'red' }}">
            <div class="card-val">${{ number_format($corte['saldo_neto'], 2) }}</div>
            <div class="card-label">Saldo Neto</div>
        </td>
    </tr>
</table>

{{-- ── VENTAS POR TIPO ── --}}
@if(count($corte['ventas_por_tipo']))
<h2>Ventas por Tipo</h2>
<table>
    <thead>
        <tr>
            <th>Tipo</th>
            <th class="center">Transacciones</th>
            <th class="center">Artículos</th>
            <th class="right">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($corte['ventas_por_tipo'] as $t)
        <tr>
            <td>{{ $t['tipo'] === 'libre' ? 'Venta Libre' : 'Catálogo' }}</td>
            <td class="center">{{ $t['ventas'] }}</td>
            <td class="center">{{ $t['cantidad'] }}</td>
            <td class="right num">${{ number_format($t['total'], 2) }}</td>
        </tr>
        @endforeach
        <tr class="total-row">
            <td>TOTAL</td>
            <td class="center">{{ $corte['count_ventas'] }}</td>
            <td class="center">{{ collect($corte['ventas_por_tipo'])->sum('cantidad') }}</td>
            <td class="right num">${{ number_format($corte['total_ventas'], 2) }}</td>
        </tr>
    </tbody>
</table>
@endif

{{-- ── TOP PRODUCTOS ── --}}
@if(count($corte['top_productos']))
<h2>Top Productos Vendidos</h2>
<table>
    <thead>
        <tr>
            <th class="center">#</th>
            <th>Producto</th>
            <th class="center">Cantidad</th>
            <th class="right">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($corte['top_productos'] as $i => $p)
        <tr>
            <td class="center">{{ $i + 1 }}</td>
            <td>{{ $p['name'] }}</td>
            <td class="center">{{ $p['cantidad'] }}</td>
            <td class="right num">${{ number_format($p['total'], 2) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- ── GASTOS ── --}}
@if(count($corte['gastos_detalle']))
<h2>Detalle de Gastos</h2>
<table>
    <thead>
        <tr>
            <th>Descripción</th>
            <th class="right">Monto</th>
            <th>Registrado por</th>
            <th class="center">Fecha</th>
        </tr>
    </thead>
    <tbody>
        @foreach($corte['gastos_detalle'] as $g)
        <tr>
            <td>{{ $g['descripcion'] }}</td>
            <td class="right num">${{ number_format($g['monto'], 2) }}</td>
            <td>{{ $g['usuario'] ?? '—' }}</td>
            <td class="center">{{ $g['fecha'] }}</td>
        </tr>
        @endforeach
        <tr class="total-row">
            <td>TOTAL GASTOS</td>
            <td class="right num">${{ number_format($corte['total_gastos'], 2) }}</td>
            <td colspan="2"></td>
        </tr>
    </tbody>
</table>
@endif

</body>
</html>
