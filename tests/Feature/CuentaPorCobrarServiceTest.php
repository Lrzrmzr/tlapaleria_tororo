<?php

namespace Tests\Feature;

use App\Models\CuentaPorCobrar;
use App\Services\CuentaPorCobrarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

class CuentaPorCobrarServiceTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    private function service(): CuentaPorCobrarService
    {
        return app(CuentaPorCobrarService::class);
    }

    private function makeCuenta(int $branchId, int $userId, int $customerId, float $monto): CuentaPorCobrar
    {
        return CuentaPorCobrar::create([
            'customer_id' => $customerId,
            'branch_id'   => $branchId,
            'user_id'     => $userId,
            'concepto'    => 'Venta a crédito',
            'monto_total' => $monto,
            'monto_cobrado' => 0,
            'saldo'       => $monto,
            'fecha'       => now()->toDateString(),
            'status'      => 'pendiente',
        ]);
    }

    public function test_registrar_cobro_reduce_el_saldo_y_marca_parcial(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $customer = $this->makeCustomer();
        $cuenta = $this->makeCuenta($branch->id, $user->id, $customer->id, 1000);

        $this->service()->registrarCobro($cuenta, [
            'monto' => 400, 'fecha_cobro' => now()->toDateString(), 'metodo_pago' => 'transferencia',
        ], $user->id);

        $cuenta->refresh();
        $this->assertSame(600.0, (float) $cuenta->saldo);
        $this->assertSame('parcial', $cuenta->status);
    }

    public function test_registrar_cobro_que_liquida_la_cuenta_marca_liquidada(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $customer = $this->makeCustomer();
        $cuenta = $this->makeCuenta($branch->id, $user->id, $customer->id, 500);

        $this->service()->registrarCobro($cuenta, [
            'monto' => 500, 'fecha_cobro' => now()->toDateString(), 'metodo_pago' => 'transferencia',
        ], $user->id);

        $this->assertSame('liquidada', $cuenta->fresh()->status);
    }

    public function test_no_permite_cobrar_mas_del_saldo_pendiente(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $customer = $this->makeCustomer();
        $cuenta = $this->makeCuenta($branch->id, $user->id, $customer->id, 300);

        $this->expectException(RuntimeException::class);

        $this->service()->registrarCobro($cuenta, [
            'monto' => 301, 'fecha_cobro' => now()->toDateString(), 'metodo_pago' => 'transferencia',
        ], $user->id);
    }

    public function test_cobro_en_efectivo_exige_caja_abierta(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $customer = $this->makeCustomer();
        $cuenta = $this->makeCuenta($branch->id, $user->id, $customer->id, 300);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('caja abierto');

        $this->service()->registrarCobro($cuenta, [
            'monto' => 100, 'fecha_cobro' => now()->toDateString(), 'metodo_pago' => 'efectivo',
        ], $user->id);
    }

    public function test_no_permite_cobrar_sobre_una_cuenta_ya_liquidada(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $customer = $this->makeCustomer();
        $cuenta = $this->makeCuenta($branch->id, $user->id, $customer->id, 100);

        $this->service()->registrarCobro($cuenta, [
            'monto' => 100, 'fecha_cobro' => now()->toDateString(), 'metodo_pago' => 'transferencia',
        ], $user->id);

        $this->expectException(RuntimeException::class);

        $this->service()->registrarCobro($cuenta->fresh(), [
            'monto' => 1, 'fecha_cobro' => now()->toDateString(), 'metodo_pago' => 'transferencia',
        ], $user->id);
    }
}
