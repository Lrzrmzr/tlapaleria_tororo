<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

class VentaServiceTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    private function service(): VentaService
    {
        return app(VentaService::class);
    }

    public function test_registrar_catalogo_descuenta_stock_y_registra_kardex(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $product = $this->makeProduct(['price' => 50]);
        $this->makeInventory($product, $branch, 10);
        $this->abrirCaja($branch, $user);

        $venta = $this->service()->registrarCatalogo(
            [['product_id' => $product->id, 'quantity' => 3]],
            'efectivo',
            null,
            null,
            $branch->id,
            $user->id
        );

        $this->assertSame(150.0, (float) $venta->total);
        $this->assertSame(7, $product->branchInventory()->first()->stock);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'tipo'       => 'salida',
            'cantidad'   => 3,
        ]);
    }

    public function test_registrar_catalogo_rechaza_venta_con_stock_insuficiente_y_no_deja_estado_parcial(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $product = $this->makeProduct();
        $this->makeInventory($product, $branch, 2);
        $this->abrirCaja($branch, $user);

        $this->expectException(RuntimeException::class);

        try {
            $this->service()->registrarCatalogo(
                [['product_id' => $product->id, 'quantity' => 5]],
                'efectivo',
                null,
                null,
                $branch->id,
                $user->id
            );
        } finally {
            // La transacción debe haber revertido: ni venta ni movimiento de inventario.
            $this->assertSame(0, Sale::count());
            $this->assertSame(0, InventoryMovement::count());
            $this->assertSame(2, $product->branchInventory()->first()->stock);
        }
    }

    public function test_registrar_libre_en_efectivo_sin_sucursal_resuelta_lanza_excepcion(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Selecciona una sucursal');

        // branchId null: mismo caso que root sin sucursal seleccionada.
        $this->service()->registrarLibre(['descripcion' => 'Venta libre', 'total' => 50], null, $user->id);
    }

    public function test_registrar_catalogo_en_efectivo_exige_caja_abierta(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $product = $this->makeProduct();
        $this->makeInventory($product, $branch, 10);
        // Sin abrir caja.

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('caja abierto');

        $this->service()->registrarCatalogo(
            [['product_id' => $product->id, 'quantity' => 1]],
            'efectivo',
            null,
            null,
            $branch->id,
            $user->id
        );
    }

    public function test_registrar_catalogo_con_tarjeta_no_requiere_caja_abierta(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $product = $this->makeProduct();
        $this->makeInventory($product, $branch, 10);

        $venta = $this->service()->registrarCatalogo(
            [['product_id' => $product->id, 'quantity' => 1]],
            'tarjeta',
            null,
            null,
            $branch->id,
            $user->id
        );

        $this->assertSame('completed', $venta->status);
    }

    public function test_idempotency_key_evita_duplicar_la_venta(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $product = $this->makeProduct();
        $this->makeInventory($product, $branch, 10);
        $this->abrirCaja($branch, $user);

        $items = [['product_id' => $product->id, 'quantity' => 1]];

        $primera = $this->service()->registrarCatalogo($items, 'efectivo', null, 'clave-123', $branch->id, $user->id);
        $segunda = $this->service()->registrarCatalogo($items, 'efectivo', null, 'clave-123', $branch->id, $user->id);

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, Sale::count());
        $this->assertSame(9, $product->branchInventory()->first()->stock);
    }

    public function test_cancelar_revierte_el_stock_y_no_permite_doble_cancelacion(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $product = $this->makeProduct();
        $this->makeInventory($product, $branch, 10);
        $this->abrirCaja($branch, $user);

        $venta = $this->service()->registrarCatalogo(
            [['product_id' => $product->id, 'quantity' => 4]],
            'efectivo',
            null,
            null,
            $branch->id,
            $user->id
        );
        $this->assertSame(6, $product->branchInventory()->first()->stock);

        $this->service()->cancelar($venta, 'Cliente se arrepintió', $user->id);

        $this->assertSame(10, $product->branchInventory()->first()->stock);
        $this->assertSame('cancelled', $venta->fresh()->status);

        $this->expectException(RuntimeException::class);
        $this->service()->cancelar($venta->fresh(), 'Otra vez', $user->id);
    }
}
