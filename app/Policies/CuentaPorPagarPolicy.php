<?php

namespace App\Policies;

use App\Models\CuentaPorPagar;
use App\Models\User;
use App\Policies\Concerns\ChecksBranchScope;

class CuentaPorPagarPolicy
{
    use ChecksBranchScope;

    // Editar montos/fechas de una cuenta es solo de encargado hacia arriba;
    // el trabajador puede registrar cobros/pagos, no reescribir la deuda.
    public function update(User $user, CuentaPorPagar $cuenta): bool
    {
        return $user->isManager() && $this->inUserBranch($user, $cuenta->branch_id);
    }

    public function delete(User $user, CuentaPorPagar $cuenta): bool
    {
        return ($user->isRoot() || $user->isBranchAdmin()) && $this->inUserBranch($user, $cuenta->branch_id);
    }

    public function registrarPago(User $user, CuentaPorPagar $cuenta): bool
    {
        return $this->inUserBranch($user, $cuenta->branch_id);
    }
}
