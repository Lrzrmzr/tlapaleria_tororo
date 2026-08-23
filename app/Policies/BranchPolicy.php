<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    /**
     * Crear, editar, desactivar o restaurar sucursales es exclusivo de root:
     * son la unidad de aislamiento de todo el sistema, no algo que un
     * administrador de sucursal deba poder tocar.
     */
    public function manage(User $user): bool
    {
        return $user->isRoot();
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Branch $branch): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $this->manage($user);
    }

    /**
     * Consultar inventario/estadísticas de una sucursal: root ve todas,
     * cualquier otro usuario solo la propia.
     */
    public function view(User $user, Branch $branch): bool
    {
        return $user->isRoot() || (int) $user->branch_id === (int) $branch->id;
    }
}
