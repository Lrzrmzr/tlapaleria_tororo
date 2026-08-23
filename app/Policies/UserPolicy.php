<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Root ve/gestiona todos los usuarios; un administrador de sucursal solo
     * gestiona la gestión de usuarios en general (el alcance por sucursal se
     * aplica aparte, al construir la consulta).
     */
    public function viewAny(User $user): bool
    {
        return $user->isRoot() || $user->isBranchAdmin();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Un administrador de sucursal solo puede asignar roles a usuarios de su
     * propia sucursal, y nunca a un usuario root. Root puede asignar cualquier
     * rol a cualquier usuario (la restricción de "solo root asigna el rol
     * root" se valida aparte, ya que depende del rol destino, no del usuario).
     */
    public function assignRole(User $user, User $target): bool
    {
        if (!$this->viewAny($user)) {
            return false;
        }

        if ($user->isRoot()) {
            return true;
        }

        return !$target->isRoot() && $target->branch_id !== null && $target->branch_id === $user->branch_id;
    }

    /**
     * Misma regla de alcance para reasignar sucursal: un administrador de
     * sucursal solo puede mover usuarios sin sucursal o ya asignados a la suya.
     */
    public function assignBranch(User $user, User $target): bool
    {
        if (!$this->viewAny($user)) {
            return false;
        }

        if ($user->isRoot()) {
            return true;
        }

        if ($target->isRoot()) {
            return false;
        }

        return $target->branch_id === null || $target->branch_id === $user->branch_id;
    }
}
