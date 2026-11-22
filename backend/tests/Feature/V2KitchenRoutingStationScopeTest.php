<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\RestaurantKitchenService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class V2KitchenRoutingStationScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_station_branch_authority_precedes_other_validation_in_ar_en_without_denied_writes(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $makeStore = function (string $code) use ($typeId): int {
            $storeId = (int) DB::table('stores')->insertGetId([
                'store_type_id' => $typeId,
                'code' => $code.'-'.Str::upper(Str::random(6)),
                'name' => $code,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->enableRestaurantSaasForTestStore($storeId);

            return $storeId;
        };
        $storeId = $makeStore('KITCHEN-SCOPE-OWN');
        $otherStoreId = $makeStore('KITCHEN-SCOPE-FOREIGN');
        $makeBranch = function (int $forStore, string $code): SmartOrderingBranch {
            $siteId = (int) DB::table('smart_ordering_sites')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'store_id' => $forStore,
                'slug' => 'kitchen-'.Str::lower(Str::random(8)),
                'runtime_state' => 'ENABLED',
                'master_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('smart_ordering_entitlements')->insert([
                'site_id' => $siteId,
                'branch_id' => null,
                'scope_key' => 'site',
                'module' => 'kitchen_routing',
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return SmartOrderingBranch::query()->create([
                'public_id' => (string) Str::uuid(),
                'site_id' => $siteId,
                'code' => $code,
                'name_en' => $code,
                'timezone' => 'Asia/Kuwait',
                'is_active' => true,
                'accepting_orders' => true,
                'supports_dine_in' => true,
                'supports_pickup' => true,
                'supports_delivery' => true,
                'allow_scheduled' => true,
                'pickup_lead_minutes' => 0,
                'max_schedule_days' => 7,
                'service_charge' => 0,
            ]);
        };
        $owned = $makeBranch($storeId, 'OWN');
        $sibling = $makeBranch($storeId, 'SIBLING');
        $foreign = $makeBranch($otherStoreId, 'FOREIGN');

        $actor = User::query()->create([
            'name' => 'Kitchen test manager',
            'email' => 'kitchen-scope-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $stationFor = function (SmartOrderingBranch $branch, string $name): string {
            app(RestaurantKitchenService::class)->createStation($branch, [
                'name_en' => $name,
                'name_ar' => 'محطة',
                'capacity_limit' => 5,
                'is_active' => true,
            ]);
            return (string) DB::table('smart_ordering_kitchen_stations')
                ->where('branch_id', $branch->getKey())
                ->where('name_en', $name)->value('public_id');
        };
        $ownedStation = $stationFor($owned, 'Owned grill');
        $inactiveStation = $stationFor($owned, 'Inactive grill');
        DB::table('smart_ordering_kitchen_stations')
            ->where('public_id', $inactiveStation)
            ->update(['is_active' => false, 'updated_at' => now()]);
        $siblingStation = $stationFor($sibling, 'Sibling grill');
        $foreignStation = $stationFor($foreign, 'Foreign grill');
        $url = route('admin.smart-ordering.kitchen.rules.store', $owned->public_id);
        $valid = [
            'match_type' => 'all',
            'priority' => 10,
            'is_active' => true,
        ];
        $beforeRules = DB::table('smart_ordering_kitchen_routing_rules')->count();
        $beforeAudit = DB::table('audit_logs')->count();
        $localeBefore = app()->getLocale();

        try {
            foreach (['ar', 'en'] as $locale) {
                $actor->forceFill(['locale' => $locale])->saveQuietly();
                app()->setLocale($locale);
                foreach ([$foreignStation, $siblingStation, $inactiveStation, (string) Str::uuid()] as $notOwned) {
                    foreach ([
                        ['station_public_id' => $notOwned],
                        ['station_public_id' => $notOwned, 'match_type' => 'INVALID'],
                        [...$valid, 'station_public_id' => $notOwned],
                    ] as $payload) {
                        $this->actingAs($actor->fresh())
                            ->postJson($url, $payload)->assertNotFound();
                    }
                }
                foreach ([
                    ['station_public_id' => 'malformed', ...$valid],
                    ['station_public_id' => ['nested'], ...$valid],
                    ['station_public_id' => $ownedStation, 'match_type' => 'INVALID'],
                    ['station_public_id' => $ownedStation, 'match_type' => 'all'],
                ] as $payload) {
                    $this->actingAs($actor->fresh())
                        ->postJson($url, $payload)->assertUnprocessable();
                }
            }
        } finally {
            app()->setLocale($localeBefore);
        }

        $this->assertSame($beforeRules, DB::table('smart_ordering_kitchen_routing_rules')->count());
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
        $this->actingAs($actor->fresh())->post($url, [
            ...$valid, 'station_public_id' => $ownedStation,
        ])->assertRedirect();
        $this->assertDatabaseHas('smart_ordering_kitchen_routing_rules', [
            'branch_id' => (int) $owned->getKey(),
            'station_id' => (int) DB::table('smart_ordering_kitchen_stations')
                ->where('public_id', $ownedStation)->value('id'),
            'match_type' => 'all',
            'priority' => 10,
        ]);
        $this->assertSame($beforeRules + 1, DB::table('smart_ordering_kitchen_routing_rules')->count());
        $this->assertSame(0, DB::table('smart_ordering_kitchen_routing_rules')
            ->whereIn('branch_id', [$sibling->getKey(), $foreign->getKey()])->count());
    }
}
