<?php

namespace App\Policies;

use App\Models\User;

class BranchTransferPolicy
{
    /**
     * Solo root, o un usuario de la sucursal ORIGEN, puede registrar un
     * traspaso: el traspaso descuenta stock del origen de inmediato (estado
     * 'completado'), así que la sucursal que entrega es la que lo autoriza.
     *
     * Auditoría run-1: antes también se permitía al usuario de la sucursal
     * destino, lo que dejaba "jalar" stock de cualquier otra sucursal sin su
     * participación, contradiciendo la UI ("Solo puedes enviar desde tu propia
     * sucursal").
     */
    public function create(User $user, int $branchOrigenId, int $branchDestinoId): bool
    {
        return $user->isRoot()
            || ($user->branch_id !== null && (int) $user->branch_id === $branchOrigenId);
    }
}
