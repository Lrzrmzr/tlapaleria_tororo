<?php

namespace App\Policies;

use App\Models\CuentaPorCobrar;
use App\Models\User;
use App\Policies\Concerns\ChecksBranchScope;

class CuentaPorCobrarPolicy
{
    use ChecksBranchScope;

    public function update(User $user, CuentaPorCobrar $cuenta): bool
    {
        return $this->inUserBranch($user, $cuenta->branch_id);
    }

    public function delete(User $user, CuentaPorCobrar $cuenta): bool
    {
        return ($user->isRoot() || $user->isBranchAdmin()) && $this->inUserBranch($user, $cuenta->branch_id);
    }

    public function registrarCobro(User $user, CuentaPorCobrar $cuenta): bool
    {
        return $this->inUserBranch($user, $cuenta->branch_id);
    }
}
