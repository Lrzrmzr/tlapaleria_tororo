<?php

namespace Tests\Feature;

use App\Services\GastoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

class GastoServiceTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    private function service(): GastoService
    {
        return app(GastoService::class);
    }

    public function test_registrar_sin_sucursal_resuelta_lanza_excepcion(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sucursal asignada');

        $this->service()->registrar(['descripcion' => 'Compra de bolsas', 'monto' => 50], null, $user->id);
    }

    public function test_registrar_exige_caja_abierta(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('caja abierto');

        $this->service()->registrar(['descripcion' => 'Compra de bolsas', 'monto' => 50], $branch->id, $user->id);
    }

    public function test_registrar_con_caja_abierta_crea_el_gasto(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $this->abrirCaja($branch, $user);

        $gasto = $this->service()->registrar(['descripcion' => 'Compra de bolsas', 'monto' => 50], $branch->id, $user->id);

        $this->assertSame(50.0, (float) $gasto->monto);
        $this->assertSame($branch->id, $gasto->branch_id);
    }
}
