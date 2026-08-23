<?php

namespace App\Policies;

use App\Models\BranchInventory;
use App\Models\User;
use App\Policies\Concerns\ChecksBranchScope;

class BranchInventoryPolicy
{
    use ChecksBranchScope;

    public function view(User $user, BranchInventory $inventario): bool
    {
        return $this->inUserBranch($user, $inventario->branch_id);
    }

    public function create(User $user, int $branchId): bool
    {
        return $user->isRoot() || $user->branch_id === $branchId;
    }

    public function update(User $user, BranchInventory $inventario): bool
    {
        return $this->inUserBranch($user, $inventario->branch_id);
    }

    public function delete(User $user, BranchInventory $inventario): bool
    {
        return $this->inUserBranch($user, $inventario->branch_id);
    }
}
