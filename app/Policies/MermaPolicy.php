<?php

namespace App\Policies;

use App\Models\Merma;
use App\Models\User;
use App\Policies\Concerns\ChecksBranchScope;

class MermaPolicy
{
    use ChecksBranchScope;

    /**
     * Borrar una merma revierte el stock que había descontado (ver
     * MermaService::eliminar): solo root o el administrador de la sucursal
     * pueden hacerlo, igual que el resto de acciones destructivas del sistema.
     */
    public function delete(User $user, Merma $merma): bool
    {
        return ($user->isRoot() || $user->isBranchAdmin()) && $this->inUserBranch($user, $merma->branch_id);
    }
}
