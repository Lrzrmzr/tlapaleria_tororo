<?php

namespace App\Policies;

use App\Models\Cobro;
use App\Models\User;
use App\Policies\Concerns\ChecksBranchScope;

class CobroPolicy
{
    use ChecksBranchScope;

    public function delete(User $user, Cobro $cobro): bool
    {
        return ($user->isRoot() || $user->isBranchAdmin())
            && $this->inUserBranch($user, $cobro->cuentaPorCobrar->branch_id);
    }
}
