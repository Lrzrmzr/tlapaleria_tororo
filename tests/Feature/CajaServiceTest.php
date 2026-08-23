<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Gasto;
use App\Models\Sale;
use App\Services\CajaService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

class CajaServiceTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    private function service(): CajaService
    {
        return app(CajaService::class);
    }

    public function test_no_permite_dos_cajas_abiertas_simultaneas_en_la_misma_sucursal(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);

        $this->service()->abrir($branch->id, $user->id, 100);

        $this->expectException(RuntimeException::class);
        $this->service()->abrir($branch->id, $user->id, 100);
    }

    /**
     * Protección a nivel de base de datos (open_marker único), independiente
     * del chequeo de aplicación — funciona igual en pgsql/mysql/sqlite.
     */
    public function test_el_indice_unico_open_marker_bloquea_doble_apertura_a_nivel_bd(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);

        CashRegister::create([
            'branch_id' => $branch->id, 'user_id' => $user->id,
            'fondo_inicial' => 100, 'fecha_apertura' => now(), 'status' => 'abierta',
        ]);

        $this->expectException(QueryException::class);

        CashRegister::create([
            'branch_id' => $branch->id, 'user_id' => $user->id,
            'fondo_inicial' => 50, 'fecha_apertura' => now(), 'status' => 'abierta',
        ]);
    }

    public function test_permite_abrir_caja_en_sucursales_distintas_al_mismo_tiempo(): void
    {
        $branchA = $this->makeBranch();
        $branchB = $this->makeBranch();
        $user = $this->makeUser($branchA);

        $cajaA = $this->service()->abrir($branchA->id, $user->id, 100);
        $cajaB = $this->service()->abrir($branchB->id, $user->id, 50);

        $this->assertNotSame($cajaA->id, $cajaB->id);
        $this->assertTrue($this->service()->hayAbierta($branchA->id));
        $this->assertTrue($this->service()->hayAbierta($branchB->id));
    }

    public function test_assert_abierta_lanza_excepcion_si_no_hay_turno_abierto(): void
    {
        $branch = $this->makeBranch();

        $this->expectException(RuntimeException::class);
        $this->service()->assertAbierta($branch->id);
    }

    public function test_cierre_calcula_el_efectivo_esperado_conciliando_ventas_y_gastos(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $caja = $this->service()->abrir($branch->id, $user->id, 500);

        Sale::create([
            'user_id' => $user->id, 'branch_id' => $branch->id, 'total' => 300,
            'status' => 'completed', 'metodo_pago' => 'efectivo', 'fecha_venta' => now()->toDateString(),
        ]);
        Sale::create([
            'user_id' => $user->id, 'branch_id' => $branch->id, 'total' => 999,
            'status' => 'completed', 'metodo_pago' => 'tarjeta', 'fecha_venta' => now()->toDateString(),
        ]);
        Gasto::create([
            'descripcion' => 'Compra de bolsas', 'monto' => 80,
            'user_id' => $user->id, 'branch_id' => $branch->id, 'fecha_gasto' => now()->toDateString(),
        ]);

        // esperado = 500 (fondo) + 300 (venta efectivo) - 80 (gasto) = 720
        // la venta con tarjeta no debe entrar al cálculo de efectivo.
        $cerrada = $this->service()->cerrar($caja, 720, null, $user->id);

        $this->assertSame(720.0, (float) $cerrada->efectivo_esperado);
        $this->assertSame(0.0, (float) $cerrada->diferencia);
        $this->assertSame('cerrada', $cerrada->status);
    }

    public function test_cierre_registra_diferencia_cuando_el_efectivo_contado_no_coincide(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $caja = $this->service()->abrir($branch->id, $user->id, 200);

        $cerrada = $this->service()->cerrar($caja, 150, 'Faltó dinero', $user->id);

        $this->assertSame(-50.0, (float) $cerrada->diferencia);
    }
}
