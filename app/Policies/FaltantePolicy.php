<?php

namespace App\Policies;

use App\Models\Faltante;
use App\Models\User;
use App\Policies\Concerns\ChecksBranchScope;

class FaltantePolicy
{
    use ChecksBranchScope;

    public function update(User $user, Faltante $faltante): bool
    {
        return $this->inUserBranch($user, $faltante->branch_id);
    }

    public function delete(User $user, Faltante $faltante): bool
    {
        return $this->inUserBranch($user, $faltante->branch_id);
    }
}
