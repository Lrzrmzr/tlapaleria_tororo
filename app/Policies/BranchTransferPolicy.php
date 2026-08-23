<?php

namespace App\Policies;

use App\Models\User;

class BranchTransferPolicy
{
    /**
     * Solo root, o un usuario cuya sucursal sea el origen o el destino
     * del traspaso, puede registrarlo.
     */
    public function create(User $user, int $branchOrigenId, int $branchDestinoId): bool
    {
        return $user->isRoot()
            || $user->branch_id === $branchOrigenId
            || $user->branch_id === $branchDestinoId;
    }
}
