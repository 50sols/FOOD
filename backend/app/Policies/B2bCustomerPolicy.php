<?php

namespace App\Policies;

use App\Models\B2bCustomer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class B2bCustomerPolicy
{
    public function view(User $user, B2bCustomer $customer): bool
    {
        if (! $this->activeActor($user)) {
            return false;
        }

        return (int) $customer->user_id === (int) $user->getKey()
            || ($this->canAccessWholesale($user) && $user->hasPermission('customers.view'));
    }

    public function update(User $user, B2bCustomer $customer): bool
    {
        if (! $this->activeActor($user)) {
            return false;
        }

        return (int) $customer->user_id === (int) $user->getKey()
            || ($this->canAccessWholesale($user) && $user->hasPermission('customers.edit'));
    }

    public function delete(User $user, B2bCustomer $customer): bool
    {
        if (! $this->activeActor($user)) {
            return false;
        }

        return $this->canAccessWholesale($user)
            && $user->hasPermission('customers.delete');
    }

    private function activeActor(User $user): bool
    {
        return $user->is_active && DB::table('users')
            ->where('id', $user->getKey())
            ->where('is_active', true)
            ->exists();
    }

    private function canAccessWholesale(User $user): bool
    {
        $roles = array_values((array) config('admin.channels.b2b.global_roles', []));

        return $user->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->whereIn('roles.code', $roles)
            ->exists();
    }
}
