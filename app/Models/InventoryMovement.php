<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    protected $fillable = [
        'branch_id', 'product_id', 'tipo', 'cantidad',
        'stock_antes', 'stock_despues', 'motivo',
        'origen_type', 'origen_id', 'user_id',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Aplica un delta de stock sobre un BranchInventory de forma atómica
     * y deja registro del movimiento (kardex). $delta positivo = entrada,
     * negativo = salida.
     */
    public static function aplicar(
        BranchInventory $inventario,
        string $tipo,
        int $delta,
        ?string $motivo = null,
        $origen = null,
        ?int $userId = null
    ): self {
        $stockAntes = $inventario->stock;

        if ($delta >= 0) {
            $inventario->increment('stock', $delta);
        } else {
            $afectado = BranchInventory::where('id', $inventario->id)
                ->where('stock', '>=', abs($delta))
                ->decrement('stock', abs($delta));

            if (!$afectado) {
                throw new \RuntimeException('Stock insuficiente para registrar el movimiento.');
            }
            $inventario->stock = $stockAntes - abs($delta);
        }

        return self::create([
            'branch_id'     => $inventario->branch_id,
            'product_id'    => $inventario->product_id,
            'tipo'          => $tipo,
            'cantidad'      => abs($delta),
            'stock_antes'   => $stockAntes,
            'stock_despues' => $inventario->stock,
            'motivo'        => $motivo,
            'origen_type'   => $origen ? get_class($origen) : null,
            'origen_id'     => $origen?->id,
            'user_id'       => $userId,
        ]);
    }
}
