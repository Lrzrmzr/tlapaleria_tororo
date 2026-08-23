<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'barcode' => $this->barcode,
            'price' => (float) $this->price,
            'cost' => $this->cost !== null ? (float) $this->cost : null,
            'active' => $this->active,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ] : null),
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
            ])->values()),
            // Solo incluye el/los inventario(s) de sucursal que el controller haya cargado
            // explícitamente para el usuario autenticado — nunca todas las sucursales por defecto.
            'inventory' => $this->whenLoaded('branchInventory', fn () => $this->branchInventory->map(fn ($inv) => [
                'branch_id' => $inv->branch_id,
                'branch_name' => $inv->relationLoaded('branch') ? $inv->branch?->name : null,
                'stock' => $inv->stock,
                'min_stock' => $inv->min_stock,
                'max_stock' => $inv->max_stock,
                'cost' => (float) $inv->cost,
            ])->values()),
        ];
    }
}
