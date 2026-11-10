<?php

namespace Tests\Feature;

use App\Models\Gasto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

/**
 * Endurecimiento de la auditoría run-1: salidas que exponían más de lo
 * necesario (CSV interpretable como fórmulas, proveedores en el catálogo
 * público, estadísticas de inventario de todas las sucursales).
 */
class ExposicionDeDatosTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    public function test_el_csv_neutraliza_textos_que_parecen_formulas(): void
    {
        $branch = $this->makeBranch();
        $admin = $this->makeUser($branch, 'administrador');
        $this->abrirCaja($branch, $admin);

        Gasto::create([
            'branch_id'   => $branch->id,
            'user_id'     => $admin->id,
            'descripcion' => '=HYPERLINK("http://example.invalid","ver")',
            'monto'       => 10,
            'fecha_gasto' => now()->toDateString(),
        ]);

        $csv = $this->actingAs($admin)
            // Rango amplio: en sqlite el cast 'date' guarda "Y-m-d H:i:s" y un
            // whereBetween con la misma fecha en ambos extremos lo excluiría.
            ->get('/reportes/csv?fecha_desde=' . now()->subDay()->toDateString() . '&fecha_hasta=' . now()->addDay()->toDateString())
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertDoesNotMatchRegularExpression('/(^|,)"?=HYPERLINK/m', $csv);
    }

    public function test_el_catalogo_publico_no_expone_proveedores(): void
    {
        $producto = $this->makeProduct(['name' => 'Martillo']);
        $proveedor = $this->makeSupplier(['name' => 'ProveedorInterno']);
        $producto->suppliers()->attach($proveedor->id, ['cost' => 5]);

        $response = $this->getJson('/api/products')->assertOk();

        $this->assertStringNotContainsString('ProveedorInterno', $response->getContent());
        $this->assertArrayNotHasKey('suppliers', $response->json('data.0'));
    }

    public function test_las_estadisticas_de_productos_solo_cuentan_la_sucursal_del_usuario(): void
    {
        $a = $this->makeBranch(['code' => 'A']);
        $b = $this->makeBranch(['code' => 'B']);
        $trabajador = $this->makeUser($a, 'trabajador');
        $producto = $this->makeProduct(['price' => 10]);
        $this->makeInventory($producto, $a, 1);
        $this->makeInventory($producto, $b, 100);

        $this->actingAs($trabajador)->get('/productos')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.valorTotal', fn ($v) => (float) $v === 10.0)
                ->etc());
    }
}
