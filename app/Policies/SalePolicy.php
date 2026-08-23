<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;
use App\Policies\Concerns\ChecksBranchScope;

class SalePolicy
{
    use ChecksBranchScope;

    public function cancelar(User $user, Sale $venta): bool
    {
        return $this->inUserBranch($user, $venta->branch_id);
    }
}
