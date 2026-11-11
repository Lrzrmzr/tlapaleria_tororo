<?php

namespace App\Policies;

use App\Models\Gasto;
use App\Models\User;
use App\Policies\Concerns\ChecksBranchScope;

class GastoPolicy
{
    use ChecksBranchScope;

    public function view(User $user, Gasto $gasto): bool
    {
        return $this->inUserBranch($user, $gasto->branch_id);
    }

    // El trabajador registra gastos pero no puede modificar uno existente.
    public function update(User $user, Gasto $gasto): bool
    {
        return $user->isManager() && $this->inUserBranch($user, $gasto->branch_id);
    }

    public function delete(User $user, Gasto $gasto): bool
    {
        return ($user->isRoot() || $user->isBranchAdmin()) && $this->inUserBranch($user, $gasto->branch_id);
    }
}
