<?php

namespace App\Policies;

use App\Models\CashRegister;
use App\Models\User;
use App\Policies\Concerns\ChecksBranchScope;

class CashRegisterPolicy
{
    use ChecksBranchScope;

    public function cerrar(User $user, CashRegister $caja): bool
    {
        return $this->inUserBranch($user, $caja->branch_id);
    }
}
