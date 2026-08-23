<?php

namespace App\Services;

use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventarioService
{
    public function crear(array $data): BranchInventory
    {
        $exists = BranchInventory::where('branch_id', $data['branch_id'])
            ->where('product_id', $data['product_id'])
            ->exists();

        if ($exists) {
            throw new RuntimeException('Este producto ya tiene inventario en la sucursal seleccionada.');
        }

        return BranchInventory::create([
            'branch_id'  => $data['branch_id'],
            'product_id' => $data['product_id'],
            'stock'      => $data['quantity'],
            'min_stock'  => $data['min_stock'],
            'max_stock'  => $data['max_stock'] ?? 0,
            'cost'       => $data['cost'] ?? 0,
        ]);
    }

    /**
     * Actualiza mínimos/máximos/costo y, si la cantidad cambió, aplica la
     * diferencia como un movimiento de ajuste en el kardex — antes esto
     * escribía `stock` directo y el cambio quedaba invisible en InventoryMovement.
     */
    public function actualizar(BranchInventory $inventario, array $data, int $userId): BranchInventory
    {
        return DB::transaction(function () use ($inventario, $data, $userId) {
            $delta = $data['quantity'] - $inventario->stock;

            if ($delta !== 0) {
                InventoryMovement::aplicar(
                    $inventario,
                    'ajuste',
                    $delta,
                    'Edición manual de inventario',
                    null,
                    $userId
                );
            }

            $inventario->update([
                'min_stock' => $data['min_stock'],
                'max_stock' => $data['max_stock'] ?? 0,
                'cost'      => $data['cost'] ?? 0,
            ]);

            return $inventario->fresh();
        });
    }

    public function ajustarStock(BranchInventory $inventario, int $cantidad, string $tipo, ?string $motivo, int $userId): BranchInventory
    {
        $delta = $tipo === 'entrada' ? $cantidad : -$cantidad;

        InventoryMovement::aplicar(
            $inventario,
            'ajuste',
            $delta,
            $motivo ?? 'Ajuste manual de inventario',
            null,
            $userId
        );

        return $inventario->fresh();
    }
}
