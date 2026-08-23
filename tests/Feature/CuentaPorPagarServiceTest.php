<?php

namespace Tests\Feature;

use App\Models\CuentaPorPagar;
use App\Services\CuentaPorPagarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

class CuentaPorPagarServiceTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    private function service(): CuentaPorPagarService
    {
        return app(CuentaPorPagarService::class);
    }

    private function makeCuenta(int $branchId, int $userId, int $supplierId, float $monto): CuentaPorPagar
    {
        return CuentaPorPagar::create([
            'supplier_id' => $supplierId,
            'branch_id'   => $branchId,
            'user_id'     => $userId,
            'concepto'    => 'Nota de proveedor',
            'monto_total' => $monto,
            'monto_pagado'=> 0,
            'saldo'       => $monto,
            'fecha_nota'  => now()->toDateString(),
            'status'      => 'pendiente',
        ]);
    }

    public function test_registrar_pago_reduce_el_saldo_y_marca_pagada_al_completarse(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $supplier = $this->makeSupplier();
        $cuenta = $this->makeCuenta($branch->id, $user->id, $supplier->id, 800);

        $this->service()->registrarPago($cuenta, [
            'monto' => 800, 'fecha_pago' => now()->toDateString(), 'metodo_pago' => 'transferencia',
        ], $user->id);

        $this->assertSame('pagada', $cuenta->fresh()->status);
        $this->assertSame(0.0, (float) $cuenta->fresh()->saldo);
    }

    public function test_no_permite_pagar_mas_del_saldo_pendiente(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $supplier = $this->makeSupplier();
        $cuenta = $this->makeCuenta($branch->id, $user->id, $supplier->id, 250);

        $this->expectException(RuntimeException::class);

        $this->service()->registrarPago($cuenta, [
            'monto' => 250.01, 'fecha_pago' => now()->toDateString(), 'metodo_pago' => 'transferencia',
        ], $user->id);
    }

    public function test_pago_en_efectivo_exige_caja_abierta(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $supplier = $this->makeSupplier();
        $cuenta = $this->makeCuenta($branch->id, $user->id, $supplier->id, 250);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('caja abierto');

        $this->service()->registrarPago($cuenta, [
            'monto' => 100, 'fecha_pago' => now()->toDateString(), 'metodo_pago' => 'efectivo',
        ], $user->id);
    }

    public function test_pago_en_efectivo_procede_con_caja_abierta(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch);
        $supplier = $this->makeSupplier();
        $cuenta = $this->makeCuenta($branch->id, $user->id, $supplier->id, 250);
        $this->abrirCaja($branch, $user);

        $pago = $this->service()->registrarPago($cuenta, [
            'monto' => 100, 'fecha_pago' => now()->toDateString(), 'metodo_pago' => 'efectivo',
        ], $user->id);

        $this->assertSame(100.0, (float) $pago->monto);
        $this->assertSame(150.0, (float) $cuenta->fresh()->saldo);
    }
}
