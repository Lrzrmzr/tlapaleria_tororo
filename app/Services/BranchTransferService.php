<?php

namespace App\Services;

use App\Models\BranchInventory;
use App\Models\BranchTransfer;
use App\Models\BranchTransferDetail;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BranchTransferService
{
    /**
     * @param array<int, array{product_id:int, quantity:int}> $items
     */
    public function registrar(int $branchOrigenId, int $branchDestinoId, array $items, string $fecha, ?string $notas, int $userId): BranchTransfer
    {
        return DB::transaction(function () use ($branchOrigenId, $branchDestinoId, $items, $fecha, $notas, $userId) {
            $transfer = BranchTransfer::create([
                'branch_origen_id'  => $branchOrigenId,
                'branch_destino_id' => $branchDestinoId,
                'user_id'           => $userId,
                'status'            => 'completado',
                'notas'             => $notas,
                'fecha'             => $fecha,
            ]);

            foreach ($items as $item) {
                $origenInv = BranchInventory::where('branch_id', $branchOrigenId)
                    ->where('product_id', $item['product_id'])
                    ->first();

                if (!$origenInv || $origenInv->stock < $item['quantity']) {
                    $product = Product::find($item['product_id']);
                    throw new RuntimeException("Stock insuficiente de {$product?->name} en la sucursal de origen.");
                }

                BranchTransferDetail::create([
                    'branch_transfer_id' => $transfer->id,
                    'product_id'         => $item['product_id'],
                    'quantity'           => $item['quantity'],
                ]);

                InventoryMovement::aplicar(
                    $origenInv,
                    'traspaso_salida',
                    -$item['quantity'],
                    'Traspaso #' . $transfer->id,
                    $transfer,
                    $userId
                );

                $destinoInv = BranchInventory::firstOrCreate(
                    ['branch_id' => $branchDestinoId, 'product_id' => $item['product_id']],
                    ['stock' => 0, 'min_stock' => 0, 'max_stock' => 0, 'cost' => $origenInv->cost, 'active' => true]
                );

                InventoryMovement::aplicar(
                    $destinoInv,
                    'traspaso_entrada',
                    $item['quantity'],
                    'Traspaso #' . $transfer->id,
                    $transfer,
                    $userId
                );
            }

            return $transfer;
        });
    }
}
