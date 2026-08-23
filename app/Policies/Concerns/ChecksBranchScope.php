<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * Regla de alcance repetida en casi todos los recursos transaccionales:
 * root ve/edita cualquier sucursal, cualquier otro usuario solo la suya.
 */
trait ChecksBranchScope
{
    protected function inUserBranch(User $user, ?int $resourceBranchId): bool
    {
        return $user->isRoot() || ($resourceBranchId !== null && $resourceBranchId === $user->branch_id);
    }
}
