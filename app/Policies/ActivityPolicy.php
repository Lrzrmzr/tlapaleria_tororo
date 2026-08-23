<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isRoot() || $user->isBranchAdmin();
    }

    public function view(User $user, Activity $activity): bool
    {
        // El detalle completo (incluye payload crudo con posibles datos de
        // otras sucursales) queda reservado a root; el listado ya viene
        // acotado por sucursal para administradores en AuditController.
        return $user->isRoot();
    }
}
