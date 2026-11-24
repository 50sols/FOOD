<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P031: a store-scoped search result must also match its persisted order channel. */
final class V2CrossChannelGlobalOrderSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_cross_channel_order_rows_cannot_escape_tenant_search_boundaries(): void
    {
        $retail = $this->store('P031-XCH-RETAIL');
        $foreign = $this->store('P031-XCH-FOREIGN');

        $valid = $this->order($retail, 'P031-XCH-VALID');
        $wrongChannel = $this->order($retail, 'P031-XCH-WRONG');
        // Simulate an inconsistent imported/legacy row without changing the
        // parent store or granting a B2B role to this Retail administrator.
        DB::table('orders')->where('id', $wrongChannel->id)->update(['channel' => 'b2b']);
        $foreignOrder = $this->order($foreign, 'P031-XCH-FOREIGN');

        $retailActor = $this->actor('retail');
        $this->grantRetailView($retailActor, $retail);
        $superAdmin = $this->actor('super');
        $superAdmin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $countBefore = DB::table('orders')->count();
        foreach (['ar', 'en'] as $locale) {
            $retailActor->forceFill(['locale' => $locale])->saveQuietly();
            $superAdmin->forceFill(['locale' => $locale])->saveQuietly();

            // Mismatched newer rows must not crowd out valid Retail results.
            $this->actingAs($retailActor->fresh())
                ->getJson(route('admin.global-search', ['q' => 'P031-XCH-', 'limit' => 1]))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.title', '#'.$valid->id)
                ->assertJsonMissing(['title' => '#'.$wrongChannel->id])
                ->assertJsonMissing(['title' => '#'.$foreignOrder->id]);

            $this->actingAs($retailActor->fresh())
                ->getJson(route('admin.global-search', ['q' => '#'.$wrongChannel->id]))
                ->assertOk()
                ->assertJsonCount(0, 'data');

            $this->actingAs($retailActor->fresh())
                ->getJson(route('admin.global-search', ['q' => 'P031-XCH-VALID']))
                ->assertOk()
                ->assertJsonFragment([
                    'url' => route('admin.operations.orders.index', [
                        'channel' => 'b2c',
                        'store_id' => $retail->id,
                        'order' => $valid->id,
                    ], false),
                ]);

            // SUPER_ADMIN remains entitled to valid orders, not corrupted
            // channel/store combinations that generate an unsafe B2B link.
            $this->actingAs($superAdmin->fresh())
                ->getJson(route('admin.global-search', ['q' => 'P031-XCH-WRONG']))
                ->assertOk()
                ->assertJsonCount(0, 'data');
            $this->actingAs($superAdmin->fresh())
                ->getJson(route('admin.global-search', ['q' => 'P031-XCH-VALID']))
                ->assertOk()
                ->assertJsonFragment(['title' => '#'.$valid->id]);
        }

        $this->assertSame($countBefore, DB::table('orders')->count());
        $this->assertSame('b2b', DB::table('orders')->where('id', $wrongChannel->id)->value('channel'));
    }

    private function store(string $code): Store
    {
        return Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }

    private function actor(string $suffix): User
    {
        return User::query()->create([
            'name' => 'Cross Channel Search '.$suffix,
            'email' => 'p031-xch-'.$suffix.'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function grantRetailView(User $actor, Store $store): void
    {
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $permission = Permission::query()->where('code', 'orders.view')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
        ]);
    }

    private function order(Store $store, string $number): Order
    {
        $customer = Customer::query()->create([
            'type' => 'b2c',
            'name' => 'Cross Channel Search Customer',
        ]);

        return Order::query()->create([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 1,
            'grand_total' => 1,
        ]);
    }
}
