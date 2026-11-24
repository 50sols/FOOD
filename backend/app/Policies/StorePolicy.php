<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;
use App\Support\StoreAccess;
use Illuminate\Support\Facades\DB;

class StorePolicy
{
    public function __construct(private readonly StoreAccess $storeAccess) {}

    public function viewAny(User $user): bool
    {
        // A previously loaded User can remain active in memory after its
        // persisted identity has been revoked. Policy admission is authoritative.
        return $user->is_active && DB::table('users')
            ->where('id', $user->getKey())
            ->where('is_active', true)
            ->exists();
    }

    public function view(User $user, Store $store): bool
    {
        return $user->is_active && $this->storeAccess->canAccess($user, $store);
    }
}
