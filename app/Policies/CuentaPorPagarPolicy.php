<?php

namespace App\Policies;

use App\Models\CuentaPorPagar;
use App\Models\User;
use App\Policies\Concerns\ChecksBranchScope;

class CuentaPorPagarPolicy
{
    use ChecksBranchScope;

    public function update(User $user, CuentaPorPagar $cuenta): bool
    {
        return $this->inUserBranch($user, $cuenta->branch_id);
    }

    public function delete(User $user, CuentaPorPagar $cuenta): bool
    {
        return ($user->isRoot() || $user->isBranchAdmin()) && $this->inUserBranch($user, $cuenta->branch_id);
    }

    public function registrarPago(User $user, CuentaPorPagar $cuenta): bool
    {
        return $this->inUserBranch($user, $cuenta->branch_id);
    }
}
