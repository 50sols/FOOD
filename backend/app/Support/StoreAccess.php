<?php

namespace App\Support;

use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class StoreAccess
{
    public function hasGlobalStoreAccess(User $user): bool
    {
        return $user->roles()
            ->join('users as scoped_actor', 'scoped_actor.id', '=', 'role_user.user_id')
            ->where('roles.code', 'SUPER_ADMIN')
            ->where('roles.scope', 'global')
            ->where('roles.is_active', true)
            ->whereNull('roles.archived_at')
            ->where('scoped_actor.is_active', true)
            ->exists();
    }

    public function canAccess(User $user, Store|int $store): bool
    {
        $storeId = $store instanceof Store ? $store->getKey() : $store;

        // Privileged role membership never makes a disabled, deleted or
        // fabricated store a valid tenant. Query the persisted store so a
        // stale active Store model cannot bypass a concurrent deactivation.
        if ($this->hasGlobalStoreAccess($user)) {
            return Store::query()->whereKey($storeId)->where('is_active', true)->exists();
        }

        return $user->storeRoleAssignments()
            ->where('store_id', $storeId)
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->whereHas('store', fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('storeType', fn ($type) => $type->where('code', 'B2C')))
            ->whereHas('role', fn ($query) => $query
                ->where('is_active', true)
                ->whereNull('archived_at')
                ->whereIn('scope', ['store', 'both']))
            ->exists();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeStores(Builder $query, User $user): Builder
    {
        if ($this->hasGlobalStoreAccess($user)) {
            return $query;
        }

        return $query->whereIn(
            $query->getModel()->qualifyColumn('id'),
            $this->assignedStoreIdsQuery($user),
        );
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeStoreOwned(Builder $query, User $user, string $storeColumn = 'store_id'): Builder
    {
        if ($this->hasGlobalStoreAccess($user)) {
            return $query;
        }

        return $query->whereIn(
            $query->getModel()->qualifyColumn($storeColumn),
            $this->assignedStoreIdsQuery($user),
        );
    }

    private function assignedStoreIdsQuery(User $user): Builder
    {
        return UserStoreRole::query()
            ->select('user_store_roles.store_id')
            ->join('users as scoped_actor', 'scoped_actor.id', '=', 'user_store_roles.user_id')
            ->join('roles', 'roles.id', '=', 'user_store_roles.role_id')
            ->join('stores', 'stores.id', '=', 'user_store_roles.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('user_store_roles.user_id', $user->getKey())
            ->where('scoped_actor.is_active', true)
            ->where('roles.is_active', true)
            ->whereNull('roles.archived_at')
            ->where('roles.scope', 'store')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->distinct();
    }
}
