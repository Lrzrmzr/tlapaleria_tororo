<?php

namespace Tests\Feature;

use App\Services\GastoService;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

class ReporteTendenciaTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    public function test_agrupa_ventas_y_gastos_por_mes_dentro_del_anio_seleccionado(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch, 'administrador');
        $product = $this->makeProduct(['price' => 50]);
        $this->makeInventory($product, $branch, 100, ['cost' => 30]);
        $this->abrirCaja($branch, $user);

        $anio = (int) now()->year;

        // Dos ventas en meses distintos del mismo año.
        app(VentaService::class)->registrarCatalogo(
            [['product_id' => $product->id, 'quantity' => 2]],
            'efectivo',
            \Illuminate\Support\Carbon::create($anio, 1, 15)->toDateString(),
            null,
            $branch->id,
            $user->id
        );

        app(VentaService::class)->registrarCatalogo(
            [['product_id' => $product->id, 'quantity' => 3]],
            'efectivo',
            \Illuminate\Support\Carbon::create($anio, 2, 10)->toDateString(),
            null,
            $branch->id,
            $user->id
        );

        app(GastoService::class)->registrar([
            'descripcion' => 'Renta',
            'monto'       => 40,
            'fecha_gasto' => \Illuminate\Support\Carbon::create($anio, 1, 20)->toDateString(),
        ], $branch->id, $user->id);

        $response = $this->actingAs($user)
            ->get("/reportes/tendencia?granularidad=mes&anio={$anio}");

        $response->assertOk();

        $page = $response->viewData('page');
        $periodos = collect($page['props']['periodos']);
        $totales  = $page['props']['totales'];

        $enero  = $periodos->firstWhere('periodo', sprintf('%04d-01', $anio));
        $febrero = $periodos->firstWhere('periodo', sprintf('%04d-02', $anio));

        $this->assertSame(100.0, $enero['total_ventas']); // 2 * 50
        $this->assertSame(40.0, (float) $enero['utilidad_bruta']); // 2 * (50-30)
        $this->assertSame(40.0, (float) $enero['total_gastos']);
        $this->assertSame(0.0, (float) $enero['utilidad_neta']);

        $this->assertSame(150.0, $febrero['total_ventas']); // 3 * 50
        $this->assertSame(60.0, (float) $febrero['utilidad_bruta']); // 3 * (50-30)

        $this->assertSame(250.0, (float) $totales['total_ventas']);
        $this->assertSame(100.0, (float) $totales['utilidad_bruta']);
        $this->assertSame(40.0, (float) $totales['total_gastos']);
        $this->assertSame(60.0, (float) $totales['utilidad_neta']);
    }
}
