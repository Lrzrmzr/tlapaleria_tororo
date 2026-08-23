<?php

namespace App\Policies;

use App\Models\PagoProveedor;
use App\Models\User;
use App\Policies\Concerns\ChecksBranchScope;

class PagoProveedorPolicy
{
    use ChecksBranchScope;

    public function delete(User $user, PagoProveedor $pago): bool
    {
        return ($user->isRoot() || $user->isBranchAdmin())
            && $this->inUserBranch($user, $pago->cuentaPorPagar->branch_id);
    }
}
