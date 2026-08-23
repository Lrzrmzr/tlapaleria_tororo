<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;

/**
 * Helpers mínimos para levantar los datos que la lógica de negocio (ventas,
 * caja, inventario, cuentas) necesita en las pruebas, sin depender de
 * factories que el proyecto no tiene para estos modelos.
 */
trait CreatesBusinessFixtures
{
    protected function makeBranch(array $overrides = []): Branch
    {
        return Branch::create(array_merge([
            'name'   => 'Sucursal de prueba',
            'code'   => 'TEST' . uniqid(),
            'active' => true,
            'is_main' => false,
        ], $overrides));
    }

    protected function makeUser(Branch $branch, string $roleName = 'trabajador', array $overrides = []): User
    {
        $user = User::create(array_merge([
            'name'      => 'Usuario de prueba',
            'email'     => 'user' . uniqid() . '@example.com',
            'password'  => 'password',
            'branch_id' => $branch->id,
            'active'    => true,
        ], $overrides));

        $role = Role::where('name', $roleName)->first();
        if ($role) {
            $user->roles()->attach($role->id);
        }

        return $user;
    }

    protected function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name'    => 'Producto de prueba',
            'barcode' => 'BC' . uniqid(),
            'price'   => 100,
            'cost'    => 60,
            'active'  => true,
        ], $overrides));
    }

    protected function makeInventory(Product $product, Branch $branch, int $stock, array $overrides = []): BranchInventory
    {
        return BranchInventory::create(array_merge([
            'branch_id'  => $branch->id,
            'product_id' => $product->id,
            'stock'      => $stock,
            'min_stock'  => 0,
            'max_stock'  => 0,
            'cost'       => 60,
            'active'     => true,
        ], $overrides));
    }

    protected function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'name'   => 'Cliente de prueba',
            'active' => true,
        ], $overrides));
    }

    protected function makeSupplier(array $overrides = []): Supplier
    {
        return Supplier::create(array_merge([
            'name'   => 'Proveedor de prueba',
            'active' => true,
        ], $overrides));
    }

    protected function abrirCaja(Branch $branch, User $user, float $fondoInicial = 0): CashRegister
    {
        return CashRegister::create([
            'branch_id'      => $branch->id,
            'user_id'        => $user->id,
            'fondo_inicial'  => $fondoInicial,
            'fecha_apertura' => now(),
            'status'         => 'abierta',
        ]);
    }
}
