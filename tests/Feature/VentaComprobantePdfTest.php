<?php

namespace Tests\Feature;

use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

class VentaComprobantePdfTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    public function test_genera_el_pdf_para_un_usuario_de_la_misma_sucursal(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch, 'trabajador');
        $product = $this->makeProduct(['price' => 50]);
        $this->makeInventory($product, $branch, 10);
        $this->abrirCaja($branch, $user);

        $venta = app(VentaService::class)->registrarCatalogo(
            [['product_id' => $product->id, 'quantity' => 2]],
            'efectivo',
            null,
            null,
            $branch->id,
            $user->id
        );

        $response = $this->actingAs($user)->get(route('ventas.comprobante', $venta->id));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_rechaza_a_un_usuario_de_otra_sucursal(): void
    {
        $branchA = $this->makeBranch(['code' => 'A']);
        $branchB = $this->makeBranch(['code' => 'B']);
        $userA = $this->makeUser($branchA, 'trabajador');
        $userB = $this->makeUser($branchB, 'trabajador');
        $product = $this->makeProduct(['price' => 50]);
        $this->makeInventory($product, $branchA, 10);
        $this->abrirCaja($branchA, $userA);

        $venta = app(VentaService::class)->registrarCatalogo(
            [['product_id' => $product->id, 'quantity' => 1]],
            'efectivo',
            null,
            null,
            $branchA->id,
            $userA->id
        );

        $this->actingAs($userB)
            ->get(route('ventas.comprobante', $venta->id))
            ->assertForbidden();
    }
}
