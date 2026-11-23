<?php

namespace App\Enterprise\Search;

use App\Enums\StoreBusinessProfile;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\DisplayNumber;
use Illuminate\Support\Facades\DB;

final class OrderGlobalSearchProvider implements GlobalSearchProvider
{
    public function __construct(private readonly OperationalTenantScope $scope) {}

    public function search(User $actor, string $query, int $limit): array
    {
        $storeIds = $this->scope->allowedStoreIds($actor, 'orders.view');
        if ($storeIds === []) {
            return [];
        }

        // The Restaurant workspace does not grant a B2C_STORE_ADMIN the
        // Retail Orders module. The shared search must not disclose order
        // identifiers or store names that the canonical Orders route denies.
        // Resolve Restaurant candidates in one query before applying LIMIT,
        // so excluded rows cannot crowd out eligible Retail results.
        if (! $actor->hasRole('SUPER_ADMIN')) {
            $restaurantStoreIds = DB::table('stores')
                ->whereIn('id', $storeIds)
                ->where('business_profile', StoreBusinessProfile::RESTAURANT->value)
                ->pluck('id');

            foreach ($restaurantStoreIds as $storeId) {
                if ($actor->hasRole('B2C_STORE_ADMIN', (int) $storeId)) {
                    $storeIds = array_values(array_diff($storeIds, [(int) $storeId]));
                }
            }

            if ($storeIds === []) {
                return [];
            }
        }

        $displayId = DisplayNumber::referenceId($query);
        $literalPattern = LiteralLikePattern::contains($query);
        $rows = DB::table('orders')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            // A store assignment is not authority for an order in a different
            // persisted channel. Reject inconsistent legacy rows before LIMIT;
            // never fabricate a channel from the authorized store.
            ->whereRaw('UPPER(orders.channel) = store_types.code')
            ->whereIn('orders.store_id', $storeIds)
            ->where(function ($builder) use ($literalPattern, $displayId): void {
                $builder->whereRaw("orders.order_number LIKE ? ESCAPE '!'", [$literalPattern]);
                if ($displayId !== null) {
                    $builder->orWhere('orders.id', $displayId);
                }
            })
            ->orderByDesc('orders.id')
            ->limit($limit)
            ->get([
                'orders.id',
                'orders.channel',
                'orders.store_id',
                'stores.name as store_name',
            ]);

        return $rows->map(static function (object $row): array {
            $channel = strtolower((string) $row->channel);
            $channelLabel = match ($channel) {
                'b2b' => __('global_search.channels.b2b'),
                'b2c' => __('global_search.channels.b2c'),
                default => __('global_search.channels.other'),
            };

            return [
                'type' => 'order',
                'type_label' => __('global_search.types.order'),
                'title' => DisplayNumber::reference((int) $row->id),
                'subtitle' => trim((string) $row->store_name).' · '.$channelLabel,
                'url' => route('admin.operations.orders.index', [
                    'channel' => $channel,
                    'store_id' => (int) $row->store_id,
                    'order' => (int) $row->id,
                ], false),
            ];
        })->values()->all();
    }
}
