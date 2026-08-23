<?php

namespace App\Services;

use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VentaService
{
    public function __construct(private CajaService $caja)
    {
    }

    /**
     * Venta libre: monto capturado a mano, sin producto de catálogo ni costo conocido.
     */
    public function registrarLibre(array $data, ?int $branchId, int $userId): Sale
    {
        if (!SystemSetting::ventasLibresHabilitadas()) {
            throw new RuntimeException('Las ventas libres están deshabilitadas.');
        }

        if (!empty($data['idempotency_key'])) {
            $existing = Sale::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }
        }

        $metodoPago = $data['metodo_pago'] ?? 'efectivo';
        if ($metodoPago === 'efectivo') {
            // Sin sucursal resuelta (típicamente root sin turno de caja elegido)
            // CajaService::assertAbierta(null) no hace nada — hay que exigir una
            // sucursal real aquí, o la venta en efectivo queda huérfana y sin caja.
            if ($branchId === null) {
                throw new RuntimeException('Selecciona una sucursal para registrar una venta en efectivo.');
            }
            $this->caja->assertAbierta($branchId);
        }

        return DB::transaction(function () use ($data, $branchId, $userId, $metodoPago) {
            $venta = Sale::create([
                'user_id'         => $userId,
                'branch_id'       => $branchId,
                'customer_id'     => null,
                'total'           => $data['total'],
                'status'          => 'completed',
                'metodo_pago'     => $metodoPago,
                'fecha_venta'     => $data['fecha_venta'] ?? now()->toDateString(),
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            SaleDetail::create([
                'sale_id'     => $venta->id,
                'product_id'  => null,
                'tipo_venta'  => 'libre',
                'descripcion' => $data['descripcion'],
                'quantity'    => 1,
                'price'       => $data['total'],
                'cost'        => null,
                'subtotal'    => $data['total'],
                'total'       => $data['total'],
            ]);

            return $venta;
        });
    }

    /**
     * Venta de catálogo: valida stock, calcula totales con precios del
     * servidor y descuenta inventario vía el kardex (InventoryMovement).
     *
     * @param array<int, array{product_id:int, quantity:int}> $items
     */
    public function registrarCatalogo(
        array $items,
        ?string $metodoPago,
        ?string $fechaVenta,
        ?string $idempotencyKey,
        ?int $branchId,
        int $userId
    ): Sale {
        if ($idempotencyKey) {
            $existing = Sale::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
        }

        $metodoPago = $metodoPago ?? 'efectivo';

        return DB::transaction(function () use ($items, $metodoPago, $fechaVenta, $idempotencyKey, $branchId, $userId) {
            $productIds = collect($items)->pluck('product_id')->unique();
            $products   = Product::whereIn('id', $productIds)->get()->keyBy('id');

            // Root user (branch_id=null): buscar el inventario con más stock por producto
            $invQuery = BranchInventory::whereIn('product_id', $productIds);
            if ($branchId) {
                $invQuery->where('branch_id', $branchId);
            } else {
                $invQuery->orderByDesc('stock');
            }
            $inventories = $invQuery->get()->unique('product_id')->keyBy('product_id');

            $effectiveBranchId = $branchId ?? $inventories->first()?->branch_id;

            if ($metodoPago === 'efectivo') {
                $this->caja->assertAbierta($effectiveBranchId);
            }

            $totalVenta = 0;
            foreach ($items as $item) {
                $product   = $products->get($item['product_id']);
                $inventory = $inventories->get($item['product_id']);

                if (!$inventory) {
                    throw new RuntimeException("El producto {$product->name} no tiene inventario en esta sucursal.");
                }
                if ($inventory->stock < $item['quantity']) {
                    throw new RuntimeException("Stock insuficiente para {$product->name}. Disponible: {$inventory->stock}");
                }

                $totalVenta += $product->price * $item['quantity'];
            }

            $venta = Sale::create([
                'user_id'         => $userId,
                'branch_id'       => $effectiveBranchId,
                'customer_id'     => null,
                'total'           => $totalVenta,
                'status'          => 'completed',
                'metodo_pago'     => $metodoPago,
                'fecha_venta'     => $fechaVenta ?? now()->toDateString(),
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($items as $item) {
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
                    'cost'        => $inventory->cost,
                    'subtotal'    => $subtotal,
                    'total'       => $subtotal,
                ]);

                InventoryMovement::aplicar(
                    $inventory,
                    'salida',
                    -$item['quantity'],
                    'Venta #' . $venta->id,
                    $venta,
                    $userId
                );
            }

            return $venta;
        });
    }

    /**
     * Cancela una venta y devuelve el stock vendido (solo artículos de catálogo).
     */
    public function cancelar(Sale $venta, string $motivo, int $userId): Sale
    {
        if ($venta->status === 'cancelled') {
            throw new RuntimeException('Esta venta ya está cancelada.');
        }

        return DB::transaction(function () use ($venta, $motivo, $userId) {
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
                        $userId
                    );
                }
            }

            $venta->update([
                'status'        => 'cancelled',
                'cancelled_by'  => $userId,
                'cancelled_at'  => now(),
                'cancel_reason' => $motivo,
            ]);

            return $venta->fresh();
        });
    }
}
