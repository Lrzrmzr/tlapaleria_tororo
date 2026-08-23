<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

/**
 * Cubre el hallazgo alto de la auditoría: ProductApiController exponía el
 * inventario de TODAS las sucursales a cualquier usuario autenticado.
 */
class ProductApiBranchScopingTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    public function test_un_usuario_de_una_sucursal_no_ve_el_stock_de_otra_sucursal(): void
    {
        $miSucursal = $this->makeBranch(['code' => 'MIA']);
        $otraSucursal = $this->makeBranch(['code' => 'OTRA']);
        $user = $this->makeUser($miSucursal, 'trabajador');

        $product = $this->makeProduct();
        $this->makeInventory($product, $miSucursal, 5);
        $this->makeInventory($product, $otraSucursal, 999);

        Passport::actingAs($user);

        $response = $this->getJson("/api/mobile/products/{$product->id}");

        $response->assertOk();
        $inventory = $response->json('data.inventory');

        $this->assertCount(1, $inventory, 'No debe incluir inventario de otras sucursales.');
        $this->assertSame($miSucursal->id, $inventory[0]['branch_id']);
        $this->assertSame(5, $inventory[0]['stock']);
    }

    public function test_un_usuario_no_root_sin_sucursal_asignada_no_ve_ningun_inventario(): void
    {
        $branch = $this->makeBranch();
        // Estado de cuenta anómalo (la API no pasa por check.branch como la web):
        // trabajador sin sucursal asignada.
        $user = $this->makeUser($branch, 'trabajador', ['branch_id' => null]);

        $product = $this->makeProduct();
        $this->makeInventory($product, $branch, 42);

        Passport::actingAs($user);

        $response = $this->getJson("/api/mobile/products/{$product->id}");

        $response->assertOk();
        $this->assertCount(0, $response->json('data.inventory'), 'Sin sucursal asignada no debe ver inventario de ninguna sucursal.');
    }

    public function test_root_puede_ver_el_inventario_de_todas_las_sucursales(): void
    {
        $branchA = $this->makeBranch(['code' => 'A']);
        $branchB = $this->makeBranch(['code' => 'B']);
        $root = $this->makeUser($branchA, 'root', ['branch_id' => null]);

        $product = $this->makeProduct();
        $this->makeInventory($product, $branchA, 5);
        $this->makeInventory($product, $branchB, 9);

        Passport::actingAs($root);

        $response = $this->getJson("/api/mobile/products/{$product->id}");

        $response->assertOk();
        $this->assertCount(2, $response->json('data.inventory'));
    }
}
