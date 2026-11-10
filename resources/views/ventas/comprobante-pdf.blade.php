<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Comprobante de venta #{{ $venta->id }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; color: #111; padding: 10px 12px; }

        .center { text-align: center; }
        .right  { text-align: right; }
        .bold   { font-weight: bold; }
        .muted  { color: #555; }

        h1 { font-size: 14px; margin-bottom: 2px; }
        .negocio-meta { font-size: 9px; color: #444; margin-bottom: 8px; }

        .divider { border-top: 1px dashed #999; margin: 8px 0; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        thead th { font-size: 9px; text-transform: uppercase; border-bottom: 1px solid #333; padding: 3px 2px; text-align: left; }
        tbody td { font-size: 10px; padding: 3px 2px; vertical-align: top; }
        tbody tr:not(:last-child) td { border-bottom: 1px dotted #ddd; }

        .totales td { padding: 2px; font-size: 10px; }
        .totales .label { text-align: right; }
        .totales .valor { text-align: right; width: 70px; }
        .total-final td { font-size: 13px; font-weight: bold; border-top: 1px solid #333; padding-top: 4px; }

        .footer { margin-top: 14px; font-size: 9px; color: #666; text-align: center; }
    </style>
</head>
<body>

<div class="center">
    <h1>{{ $venta->branch->name ?? config('app.name') }}</h1>
    <div class="negocio-meta">
        @if($venta->branch?->address) {{ $venta->branch->address }}<br>@endif
        @if($venta->branch?->phone) Tel: {{ $venta->branch->phone }} @endif
    </div>
</div>

<div class="divider"></div>

<table>
    <tr><td class="muted">Comprobante</td><td class="right bold">#{{ $venta->id }}</td></tr>
    <tr><td class="muted">Fecha</td><td class="right">{{ optional($venta->fecha_venta)->format('d/m/Y') ?? $venta->created_at->format('d/m/Y') }} {{ $venta->created_at->format('H:i') }}</td></tr>
    <tr><td class="muted">Atendió</td><td class="right">{{ $venta->user->name }}</td></tr>
    <tr><td class="muted">Cliente</td><td class="right">{{ $venta->customer->name ?? 'Cliente general' }}</td></tr>
    <tr><td class="muted">Método de pago</td><td class="right">{{ ucfirst($venta->metodo_pago ?? 'efectivo') }}</td></tr>
</table>

<div class="divider"></div>

<table>
    <thead>
        <tr>
            <th>Descripción</th>
            <th class="right">Cant.</th>
            <th class="right">P. Unit.</th>
            <th class="right">Subtotal</th>
        </tr>
    </thead>
    <tbody>
        @foreach($venta->saleDetails as $detail)
        <tr>
            <td>{{ $detail->descripcion_completa }}</td>
            <td class="right">{{ $detail->quantity }}</td>
            <td class="right">${{ number_format($detail->price, 2) }}</td>
            <td class="right">${{ number_format($detail->subtotal, 2) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<div class="divider"></div>

<table class="totales">
    <tr class="total-final">
        <td class="label">TOTAL</td>
        <td class="valor">${{ number_format($venta->total, 2) }}</td>
    </tr>
</table>

<div class="footer">
    Este comprobante no tiene validez fiscal.<br>
    Generado {{ now()->format('d/m/Y H:i') }}
</div>

</body>
</html>
