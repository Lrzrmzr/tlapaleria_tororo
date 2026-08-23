<?php

namespace Tests\Feature;

use App\Models\Merma;
use App\Services\MermaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

class MermaServiceTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    private function service(): MermaService
    {
        return app(MermaService::class);
    }

    public function test_registrar_descuenta_stock(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $product = $this->makeProduct();
        $this->makeInventory($product, $branch, 10);

        $merma = $this->service()->registrar([
            'branch_id' => $branch->id, 'product_id' => $product->id,
            'quantity' => 3, 'motivo' => 'dañado', 'fecha' => now()->toDateString(),
        ], $user->id);

        $this->assertSame(7, $product->branchInventory()->first()->stock);
        $this->assertSame(3, $merma->quantity);
    }

    public function test_registrar_rechaza_mermar_mas_stock_del_disponible(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $product = $this->makeProduct();
        $this->makeInventory($product, $branch, 2);

        $this->expectException(RuntimeException::class);

        $this->service()->registrar([
            'branch_id' => $branch->id, 'product_id' => $product->id,
            'quantity' => 5, 'motivo' => 'dañado', 'fecha' => now()->toDateString(),
        ], $user->id);
    }

    public function test_eliminar_revierte_el_stock_descontado(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $product = $this->makeProduct();
        $this->makeInventory($product, $branch, 10);

        $merma = $this->service()->registrar([
            'branch_id' => $branch->id, 'product_id' => $product->id,
            'quantity' => 4, 'motivo' => 'caducado', 'fecha' => now()->toDateString(),
        ], $user->id);
        $this->assertSame(6, $product->branchInventory()->first()->stock);

        $this->service()->eliminar($merma, $user->id);

        $this->assertSame(10, $product->branchInventory()->first()->stock);
        $this->assertNull(Merma::find($merma->id));
        $this->assertTrue($merma->fresh()->trashed());
    }
}
