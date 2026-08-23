<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function manage(User $user): bool
    {
        return $user->isRoot() || $user->isBranchAdmin();
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->manage($user);
    }
}
