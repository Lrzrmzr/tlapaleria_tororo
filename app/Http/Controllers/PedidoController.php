<?php

namespace App\Http\Controllers;

use App\Models\BranchInventory;
use App\Models\Branch;
use App\Models\CuentaPorPagar;
use App\Models\Gasto;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\Supplier;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PedidoController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->user_branch_id;
        $isRoot   = $request->is_root ?? false;
        $user     = Auth::user();

        $query = Purchase::with(['supplier', 'branch', 'user', 'purchaseDetails.product'])->latest();

        if (!$isRoot) {
            $query->where('branch_id', $branchId);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('supplier_id')) $query->where('supplier_id', $request->supplier_id);
        if ($request->filled('tipo_pago'))   $query->where('tipo_pago', $request->tipo_pago);
        if ($request->filled('fecha_desde')) $query->whereDate('fecha_factura', '>=', $request->fecha_desde);
        if ($request->filled('fecha_hasta')) $query->whereDate('fecha_factura', '<=', $request->fecha_hasta);

        $pedidos = $query->get()->map(fn($p) => $this->formatPedido($p))->values();

        $statsBase = Purchase::query();
        if (!$isRoot) $statsBase->where('branch_id', $branchId);

        $stats = [
            'total_pedidos'   => (clone $statsBase)->count(),
            'total_monto'     => (float) (clone $statsBase)->sum('total'),
            'pedidos_credito' => (clone $statsBase)->where('tipo_pago', 'credito')->count(),
            'pedidos_hoy'     => (clone $statsBase)->whereDate('created_at', today())->count(),
        ];

        $suppliers = Supplier::active()->orderBy('name')->get(['id', 'name']);
        $branches  = $isRoot ? Branch::where('active', true)->orderBy('name')->get(['id', 'name']) : collect();

        $productosQuery = Product::where('active', true)->orderBy('name');
        $productos = $productosQuery->get()->map(fn($p) => [
            'id'      => $p->id,
            'name'    => $p->name,
            'barcode' => $p->barcode,
            'cost'    => (float) $p->cost,
        ]);

        return Inertia::render('Pedidos/Index', [
            'pedidos'                  => $pedidos,
            'stats'                    => $stats,
            'suppliers'                => $suppliers,
            'branches'                 => $branches,
            'productos'                => $productos,
            'comprasLibresHabilitadas' => SystemSetting::comprasLibresHabilitadas(),
            'filters'                  => $request->only(['supplier_id', 'tipo_pago', 'branch_id', 'fecha_desde', 'fecha_hasta']),
            'user'                     => [
                'id'       => $user->id,
                'name'     => $user->name,
                'isRoot'   => $isRoot,
                'branchId' => $branchId,
            ],
            'groqConfigurado' => !empty(config('services.groq.api_key')),
        ]);
    }

    public function store(Request $request)
    {
        $branchId = $request->user_branch_id;
        $isRoot   = $request->is_root ?? false;
        $user     = Auth::user();

        $data = $request->validate([
            'supplier_id'              => 'required|exists:suppliers,id',
            'branch_id'                => 'required|exists:branches,id',
            'tipo_pago'                => 'required|in:contado,credito',
            'numero_factura'           => 'nullable|string|max:100',
            'numero_pedido'            => 'nullable|string|max:100',
            'fecha_factura'            => 'required|date',
            'fecha_vencimiento'        => 'required_if:tipo_pago,credito|nullable|date',
            'notas'                    => 'nullable|string|max:1000',
            'imagen'                   => 'nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'items'                    => 'required|array|min:1',
            'items.*.tipo_item'        => 'required|in:catalogo,libre',
            'items.*.product_id'       => 'nullable|exists:products,id',
            'items.*.descripcion'      => 'nullable|string|max:255',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.cost'             => 'required|numeric|min:0',
        ]);

        if (!$isRoot) {
            $data['branch_id'] = $branchId;
        }

        if (!SystemSetting::comprasLibresHabilitadas()) {
            foreach ($data['items'] as $item) {
                if ($item['tipo_item'] === 'libre') {
                    return redirect()->back()->withErrors(['error' => 'Las compras libres están deshabilitadas por el administrador.']);
                }
            }
        }

        DB::beginTransaction();
        try {
            $total = collect($data['items'])->sum(fn($i) => $i['quantity'] * $i['cost']);

            $imagenPath = null;
            if ($request->hasFile('imagen')) {
                $imagenPath = $request->file('imagen')->store('notas_proveedor/' . date('Y/m'), 'public');
            }

            $purchase = Purchase::create([
                'supplier_id'       => $data['supplier_id'],
                'branch_id'         => $data['branch_id'],
                'user_id'           => $user->id,
                'tipo_pago'         => $data['tipo_pago'],
                'numero_factura'    => $data['numero_factura'] ?? null,
                'numero_pedido'     => $data['numero_pedido'] ?? null,
                'fecha_factura'     => $data['fecha_factura'],
                'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null,
                'notas'             => $data['notas'] ?? null,
                'imagen_path'       => $imagenPath,
                'total'             => $total,
                'status'            => 'completed',
            ]);

            foreach ($data['items'] as $item) {
                $subtotal = $item['quantity'] * $item['cost'];
                $product  = ($item['tipo_item'] === 'catalogo' && !empty($item['product_id']))
                    ? Product::find($item['product_id'])
                    : null;

                PurchaseDetail::create([
                    'purchase_id' => $purchase->id,
                    'product_id'  => $product?->id,
                    'descripcion' => $product ? $product->name : ($item['descripcion'] ?? ''),
                    'quantity'    => $item['quantity'],
                    'cost'        => $item['cost'],
                    'subtotal'    => $subtotal,
                ]);

                if ($product) {
                    $inv = BranchInventory::firstOrCreate(
                        ['branch_id' => $data['branch_id'], 'product_id' => $product->id],
                        ['stock' => 0, 'min_stock' => 0, 'max_stock' => 0, 'cost' => $item['cost'], 'active' => true]
                    );
                    if ($item['cost'] > 0) {
                        $inv->update(['cost' => $item['cost']]);
                    }
                    InventoryMovement::aplicar(
                        $inv,
                        'entrada',
                        $item['quantity'],
                        'Pedido #' . $purchase->id,
                        $purchase,
                        $user->id
                    );
                }
            }

            if ($data['tipo_pago'] === 'credito') {
                $concepto = 'Pedido #' . $purchase->id;
                if (!empty($data['numero_factura'])) {
                    $concepto .= ' — Fact. ' . $data['numero_factura'];
                }

                CuentaPorPagar::create([
                    'supplier_id'       => $data['supplier_id'],
                    'branch_id'         => $data['branch_id'],
                    'user_id'           => $user->id,
                    'numero_nota'       => $data['numero_factura'] ?? null,
                    'concepto'          => $concepto,
                    'monto_total'       => $total,
                    'monto_pagado'      => 0,
                    'saldo'             => $total,
                    'fecha_nota'        => $data['fecha_factura'],
                    'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null,
                    'status'            => 'pendiente',
                    'notas'             => $data['notas'] ?? null,
                ]);
            } else {
                $supplier    = Supplier::find($data['supplier_id']);
                $descripcion = 'Compra a ' . ($supplier?->name ?? 'proveedor') . ' (Pedido #' . $purchase->id . ')';
                if (!empty($data['numero_factura'])) {
                    $descripcion .= ' — Fact. ' . $data['numero_factura'];
                }

                Gasto::create([
                    'descripcion' => $descripcion,
                    'monto'       => $total,
                    'user_id'     => $user->id,
                    'branch_id'   => $data['branch_id'],
                    'fecha_gasto' => $data['fecha_factura'],
                ]);
            }

            DB::commit();

            $msg = $data['tipo_pago'] === 'credito'
                ? 'Pedido registrado. Se creó cuenta por pagar por $' . number_format($total, 2) . '.'
                : 'Pedido registrado. Inventario actualizado y se registró un gasto por $' . number_format($total, 2) . '.';

            return redirect()->back()->with('success', $msg);

        } catch (\Exception $e) {
            DB::rollBack();
            if ($imagenPath) {
                Storage::disk('public')->delete($imagenPath);
            }
            Log::error('PedidoController::store', ['error' => $e->getMessage()]);
            return redirect()->back()->withErrors(['error' => 'Error al registrar el pedido: ' . $e->getMessage()]);
        }
    }

    public function parseDocumento(Request $request)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        $apiKey = config('services.groq.api_key');
        if (!$apiKey) {
            return response()->json(['error' => 'El análisis con IA no está configurado. Agrega GROQ_API_KEY en el archivo .env.'], 422);
        }

        $file    = $request->file('archivo');
        $mime    = $file->getMimeType();
        $base64  = base64_encode(file_get_contents($file->getRealPath()));
        $dataUrl = "data:{$mime};base64,{$base64}";

        $prompt = <<<PROMPT
Analiza esta nota/remisión/factura de proveedor de una tlapalería (ferretería) en México.
Extrae ÚNICAMENTE la siguiente información y responde SOLO con JSON válido, sin texto adicional, sin backticks:
{
  "proveedor_nombre": "nombre del proveedor si aparece, o null",
  "numero_factura": "número de factura/nota/remisión, o null",
  "numero_pedido": "número de pedido del proveedor, o null",
  "fecha": "fecha en formato YYYY-MM-DD, o null",
  "items": [
    {
      "descripcion": "nombre del artículo",
      "cantidad": número entero,
      "precio_unitario": número decimal,
      "subtotal": número decimal
    }
  ],
  "total": número decimal o null,
  "tipo_nota": "factura o remision o nota o otro"
}
Si no puedes leer un campo claramente, devuelve null en ese campo.
PROMPT;

        try {
            $resp = Http::withToken($apiKey)
                ->timeout(45)
                ->post(config('services.groq.url'), [
                    'model'       => config('services.groq.model_vision'),
                    'temperature' => 0.1,
                    'max_tokens'  => 2000,
                    'messages'    => [
                        [
                            'role'    => 'user',
                            'content' => [
                                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                                ['type' => 'text', 'text' => $prompt],
                            ],
                        ],
                    ],
                ]);

            if (!$resp->successful()) {
                Log::error('Groq vision error', ['status' => $resp->status(), 'body' => $resp->body()]);
                return response()->json(['error' => 'No se pudo analizar el documento. Verifica que el modelo de visión esté configurado.'], 422);
            }

            $content = $resp->json('choices.0.message.content') ?? '';

            // Strip backticks if the model wrapped the JSON
            if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $content, $m)) {
                $content = $m[1];
            }

            $parsed = json_decode(trim($content), true);
            if (!$parsed) {
                return response()->json(['error' => 'El modelo no pudo extraer datos estructurados. Intenta con una imagen más clara.'], 422);
            }

            return response()->json(['data' => $parsed]);

        } catch (\Exception $e) {
            Log::error('PedidoController::parseDocumento', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Error al contactar el servicio de IA.'], 502);
        }
    }

    private function formatPedido(Purchase $p): array
    {
        return [
            'id'                => $p->id,
            'tipo_pago'         => $p->tipo_pago ?? 'contado',
            'numero_factura'    => $p->numero_factura,
            'numero_pedido'     => $p->numero_pedido,
            'fecha_factura'     => $p->fecha_factura?->toDateString(),
            'fecha_vencimiento' => $p->fecha_vencimiento?->toDateString(),
            'total'             => (float) $p->total,
            'status'            => $p->status,
            'notas'             => $p->notas,
            'imagen_url'        => $p->imagen_path ? Storage::disk('public')->url($p->imagen_path) : null,
            'supplier'          => $p->supplier ? ['id' => $p->supplier->id, 'name' => $p->supplier->name] : null,
            'branch'            => $p->branch   ? ['id' => $p->branch->id,   'name' => $p->branch->name]   : null,
            'user'              => $p->user     ? ['id' => $p->user->id,     'name' => $p->user->name]     : null,
            'items'             => $p->purchaseDetails->map(fn($d) => [
                'id'          => $d->id,
                'product_id'  => $d->product_id,
                'descripcion' => $d->descripcion ?? ($d->product?->name ?? ''),
                'quantity'    => $d->quantity,
                'cost'        => (float) $d->cost,
                'subtotal'    => (float) $d->subtotal,
            ])->values()->toArray(),
            'created_at' => $p->created_at->format('d/m/Y H:i'),
        ];
    }
}
