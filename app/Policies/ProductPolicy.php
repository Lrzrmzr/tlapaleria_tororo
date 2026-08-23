<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * El catálogo de productos es compartido entre sucursales: solo root o un
     * administrador de sucursal pueden crear/editar/borrar productos.
     */
    public function manage(User $user): bool
    {
        return $user->isRoot() || $user->isBranchAdmin();
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->manage($user);
    }
}
