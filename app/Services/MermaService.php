<?php

namespace App\Services;

use App\Models\BranchInventory;
use App\Models\InventoryMovement;
use App\Models\Merma;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MermaService
{
    public function registrar(array $data, int $userId): Merma
    {
        $inventario = BranchInventory::where('branch_id', $data['branch_id'])
            ->where('product_id', $data['product_id'])
            ->first();

        if (!$inventario || $inventario->stock < $data['quantity']) {
            throw new RuntimeException('Stock insuficiente para registrar esta merma.');
        }

        return DB::transaction(function () use ($data, $userId, $inventario) {
            $merma = Merma::create([
                'branch_id'  => $data['branch_id'],
                'product_id' => $data['product_id'],
                'quantity'   => $data['quantity'],
                'motivo'     => $data['motivo'],
                'notas'      => $data['notas'] ?? null,
                'user_id'    => $userId,
                'fecha'      => $data['fecha'],
            ]);

            InventoryMovement::aplicar(
                $inventario,
                'merma',
                -$data['quantity'],
                ucfirst($data['motivo']) . ' — Merma #' . $merma->id,
                $merma,
                $userId
            );

            return $merma;
        });
    }

    /**
     * Elimina el registro de merma y revierte el stock que había descontado
     * (antes el borrado dejaba el inventario permanentemente corto).
     */
    public function eliminar(Merma $merma, int $userId): void
    {
        DB::transaction(function () use ($merma, $userId) {
            $inventario = BranchInventory::where('branch_id', $merma->branch_id)
                ->where('product_id', $merma->product_id)
                ->first();

            if ($inventario) {
                InventoryMovement::aplicar(
                    $inventario,
                    'entrada',
                    $merma->quantity,
                    'Reversión de merma #' . $merma->id,
                    $merma,
                    $userId
                );
            }

            $merma->delete();
        });
    }
}
