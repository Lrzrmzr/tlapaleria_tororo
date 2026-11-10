<?php

namespace Tests\Feature;

use App\Models\BranchInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

class BranchTransferAuthorizationTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    public function test_un_usuario_del_destino_no_puede_jalar_stock_de_otra_sucursal(): void
    {
        $a = $this->makeBranch(['code' => 'A']);
        $b = $this->makeBranch(['code' => 'B']);
        $trabajadorA = $this->makeUser($a, 'trabajador');
        $producto = $this->makeProduct();
        $invB = $this->makeInventory($producto, $b, 10);

        $this->actingAs($trabajadorA)->post(route('traspasos.store'), [
            'branch_origen_id'  => $b->id,
            'branch_destino_id' => $a->id,
            'fecha'             => now()->toDateString(),
            'items'             => [['product_id' => $producto->id, 'quantity' => 10]],
        ])->assertForbidden();

        $this->assertSame(10, (int) BranchInventory::find($invB->id)->stock);
    }

    public function test_un_usuario_del_origen_si_puede_enviar_stock(): void
    {
        $a = $this->makeBranch(['code' => 'A']);
        $b = $this->makeBranch(['code' => 'B']);
        $trabajadorA = $this->makeUser($a, 'trabajador');
        $producto = $this->makeProduct();
        $invA = $this->makeInventory($producto, $a, 10);

        $this->actingAs($trabajadorA)->post(route('traspasos.store'), [
            'branch_origen_id'  => $a->id,
            'branch_destino_id' => $b->id,
            'fecha'             => now()->toDateString(),
            'items'             => [['product_id' => $producto->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(6, (int) BranchInventory::find($invA->id)->stock);
    }
}
