<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2RetailWebStoreSelectorShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_every_existing_retail_web_module_keeps_explicit_store_two_in_both_locales(): void
    {
        $storeOne = $this->retailStore('V2-4030-STORE-ONE');
        $storeTwo = $this->retailStore('V2-4030-STORE-TWO');
        $actor = $this->retailActor([$storeOne, $storeTwo]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            foreach ([
                'dashboard', 'products', 'inventory', 'orders', 'customers',
                'drivers', 'promotions', 'content', 'reports', 'settings',
            ] as $module) {
                $url = $module === 'dashboard'
                    ? route('admin.b2c.dashboard', [
                        'store_id' => $storeTwo,
                        'dashboard_tab' => 'inventory',
                    ])
                    : route('admin.b2c.module', [
                        'module' => $module,
                        'store_id' => $storeTwo,
                    ]);

                $this->actingAs($actor->fresh())
                    ->get($url)
                    ->assertOk()
                    ->assertViewHas('storeId', $storeTwo)
                    ->assertViewHas('storeIds', [$storeTwo])
                    ->assertViewHas('supportAccess', false)
                    ->assertSee('dir="'.$direction.'"', false);
            }
        }
    }

    public function test_malformed_present_store_selectors_never_fall_back_to_another_authorized_store(): void
    {
        $storeOne = $this->retailStore('V2-4030-A');
        $storeTwo = $this->retailStore('V2-4030-B');
        $actor = $this->retailActor([$storeOne, $storeTwo]);
        $counts = $this->businessCounts();

        $invalidQueries = [
            'store_id%5B%5D='.$storeTwo,
            'store_id%5Bkey%5D='.$storeTwo,
            'store_id=not-a-store',
            'store_id=0',
            'store_id=00'.$storeTwo,
            'store_id=999999999999999999999999999999',
            'store_id=not-a-store&store='.$storeTwo,
            'store_id='.$storeOne.'&store='.$storeTwo,
            'store%5B%5D='.$storeTwo,
            'store=not-a-store',
            'store_id='.$storeTwo.'&store=not-a-store',
        ];

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();

            foreach (['dashboard', 'products', 'inventory', 'reports', 'settings'] as $module) {
                $path = $module === 'dashboard'
                    ? route('admin.b2c.dashboard')
                    : route('admin.b2c.module', ['module' => $module]);

                foreach ($invalidQueries as $query) {
                    $this->actingAs($actor->fresh())
                        ->get($path.'?'.$query)
                        ->assertNotFound();
                }
            }
        }

        $this->assertSame($counts, $this->businessCounts());
    }

    public function test_legacy_store_alias_and_absent_selector_remain_supported_without_context_mutation(): void
    {
        $storeOne = $this->retailStore('V2-4030-DEFAULT');
        $storeTwo = $this->retailStore('V2-4030-ALIAS');
        $actor = $this->retailActor([$storeOne, $storeTwo]);

        $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'inventory', 'store' => $storeTwo]))
            ->assertOk()
            ->assertViewHas('storeId', $storeTwo)
            ->assertViewHas('storeIds', [$storeTwo]);

        $this->actingAs($actor)
            ->get(route('admin.b2c.dashboard', ['store_id' => $storeTwo, 'store' => $storeTwo]))
            ->assertOk()
            ->assertViewHas('storeId', $storeTwo);

        $singleStoreActor = $this->retailActor([$storeOne]);
        $this->actingAs($singleStoreActor)
            ->get(route('admin.b2c.dashboard', ['dashboard_tab' => 'overview']))
            ->assertOk()
            ->assertViewHas('storeId', $storeOne);
    }

    public function test_foreign_and_inactive_stores_roleless_and_inactive_actors_stay_denied(): void
    {
        $storeOne = $this->retailStore('V2-4030-OWNED');
        $foreign = $this->retailStore('V2-4030-FOREIGN');
        $inactiveStore = $this->retailStore('V2-4030-INACTIVE');
        DB::table('stores')->where('id', $inactiveStore)->update(['is_active' => false]);

        $actor = $this->retailActor([$storeOne]);
        foreach ([$foreign, $inactiveStore] as $storeId) {
            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $storeId]))
                ->assertNotFound();
        }

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)
            ->get(route('admin.b2c.dashboard', ['store_id' => $storeOne]))
            ->assertForbidden();

        $inactiveActor = $this->retailActor([$storeOne]);
        $inactiveActor->forceFill(['is_active' => false])->save();
        $this->actingAs($inactiveActor->fresh())
            ->get(route('admin.b2c.dashboard', ['store_id' => $storeOne]))
            ->assertForbidden();
    }

    /** @param list<int> $storeIds */
    private function retailActor(array $storeIds): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $roleId = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey();

        foreach ($storeIds as $storeId) {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->getKey(),
                'store_id' => $storeId,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $actor;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, int> */
    private function businessCounts(): array
    {
        return [
            'stores' => DB::table('stores')->count(),
            'orders' => DB::table('orders')->count(),
            'store_products' => DB::table('store_products')->count(),
            'inventories' => DB::table('inventories')->count(),
            'promotions' => DB::table('promotions')->count(),
        ];
    }
}
