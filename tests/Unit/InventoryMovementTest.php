<?php

namespace Tests\Unit;

use App\Models\InventoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

class InventoryMovementTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    public function test_aplicar_incrementa_stock_y_deja_registro_en_el_kardex(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct();
        $inventario = $this->makeInventory($product, $branch, 10);

        $movimiento = InventoryMovement::aplicar($inventario, 'entrada', 5, 'Reabasto', null, null);

        $this->assertSame(15, $inventario->fresh()->stock);
        $this->assertSame(5, $movimiento->cantidad);
        $this->assertSame(10, $movimiento->stock_antes);
        $this->assertSame(15, $movimiento->stock_despues);
        $this->assertSame('entrada', $movimiento->tipo);
    }

    public function test_aplicar_decrementa_stock_correctamente(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct();
        $inventario = $this->makeInventory($product, $branch, 10);

        InventoryMovement::aplicar($inventario, 'salida', -4, 'Venta', null, null);

        $this->assertSame(6, $inventario->fresh()->stock);
    }

    public function test_aplicar_rechaza_dejar_el_stock_en_negativo(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct();
        $inventario = $this->makeInventory($product, $branch, 3);

        $this->expectException(RuntimeException::class);

        InventoryMovement::aplicar($inventario, 'salida', -10, 'Venta', null, null);
    }
}
