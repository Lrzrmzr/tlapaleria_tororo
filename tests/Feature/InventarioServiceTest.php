<?php

namespace Tests\Feature;

use App\Services\InventarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

class InventarioServiceTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    private function service(): InventarioService
    {
        return app(InventarioService::class);
    }

    public function test_actualizar_con_cambio_de_cantidad_genera_movimiento_de_ajuste(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $product = $this->makeProduct();
        $inventario = $this->makeInventory($product, $branch, 10);

        $this->service()->actualizar($inventario, [
            'quantity' => 16, 'min_stock' => 2, 'max_stock' => 50, 'cost' => 70,
        ], $user->id);

        $this->assertSame(16, $inventario->fresh()->stock);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id'    => $product->id,
            'tipo'          => 'ajuste',
            'cantidad'      => 6,
            'stock_antes'   => 10,
            'stock_despues' => 16,
        ]);
    }

    public function test_actualizar_sin_cambio_de_cantidad_no_genera_movimiento(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $product = $this->makeProduct();
        $inventario = $this->makeInventory($product, $branch, 10);

        $this->service()->actualizar($inventario, [
            'quantity' => 10, 'min_stock' => 3, 'max_stock' => 40, 'cost' => 65,
        ], $user->id);

        $this->assertSame(10, $inventario->fresh()->stock);
        $this->assertSame(65.0, (float) $inventario->fresh()->cost);
        $this->assertDatabaseCount('inventory_movements', 0);
    }
}
