<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P031: the canonical Retail incoming-orders queue must never borrow another store or
 * channel scope from caller-supplied query parameters.
 */
final class V2RetailIncomingOrdersSelectorIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_incoming_orders_pending_queue_rejects_malformed_and_foreign_store_selectors_in_both_locales(): void
    {
        $ownedStore = $this->store('B2C', 'P031-INCOMING-ORDERS-OWNED');
        $foreignStore = $this->store('B2C', 'P031-INCOMING-ORDERS-FOREIGN');
        $wholesaleStore = $this->store('B2B', 'P031-INCOMING-ORDERS-WHOLESALE');

        $retailActor = $this->actor('B2C_STORE_ADMIN', $ownedStore);
        $wholesaleActor = $this->actor('B2B_ADMIN');
        $rolelessActor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $before = [
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'invoices' => DB::table('invoices')->count(),
            'assignments' => DB::table('driver_assignments')->count(),
        ];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $retailActor->forceFill(['locale' => $locale])->saveQuietly();

            $this->actingAs($retailActor->fresh())
                ->get($this->incomingOrdersUrl(['store_id' => $ownedStore]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertViewHas('selectedChannel', 'b2c')
                ->assertViewHas('selectedStatus', 'pending')
                ->assertViewHas('storeIds', [$ownedStore]);

            // Query parameters may not turn this canonical Retail route into B2B.
            $this->actingAs($retailActor->fresh())
                ->get($this->incomingOrdersUrl(['store_id' => $ownedStore, 'channel' => 'b2b']))
                ->assertOk()
                ->assertViewHas('selectedChannel', 'b2c')
                ->assertViewHas('storeIds', [$ownedStore]);

            foreach ([$foreignStore, $wholesaleStore] as $forbiddenStore) {
                $this->actingAs($retailActor->fresh())
                    ->get($this->incomingOrdersUrl(['store_id' => $forbiddenStore]))
                    ->assertNotFound();
            }

            $this->actingAs($retailActor->fresh())
                ->get($this->incomingOrdersUrl(['store_id' => $ownedStore, 'store' => $ownedStore]))
                ->assertOk()
                ->assertViewHas('storeIds', [$ownedStore]);

            $this->actingAs($retailActor->fresh())
                ->get($this->incomingOrdersUrl(['store_id' => $ownedStore, 'store' => $foreignStore]))
                ->assertNotFound();

            foreach (['0', '-1', 'not-an-id', '01'] as $invalidSelector) {
                foreach (['store_id', 'store'] as $selector) {
                    $this->actingAs($retailActor->fresh())
                        ->get($this->incomingOrdersUrl([$selector => $invalidSelector]))
                        ->assertNotFound();
                }
            }

            $this->actingAs($wholesaleActor)
                ->get($this->incomingOrdersUrl(['store_id' => $ownedStore]))
                ->assertForbidden();

            $this->actingAs($rolelessActor)
                ->get($this->incomingOrdersUrl(['store_id' => $ownedStore]))
                ->assertForbidden();
        }

        foreach ($before as $table => $count) {
            $tableName = $table === 'assignments' ? 'driver_assignments' : $table;
            $this->assertSame($count, DB::table($tableName)->count(), $tableName.' was mutated by GET');
        }
    }

    /** @param array<string, int|string> $query */
    private function incomingOrdersUrl(array $query): string
    {
        return route('admin.b2c.orders.canonical', ['status' => 'pending'] + $query, false);
    }

    private function actor(string $roleCode, ?int $storeId = null): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

        if ($storeId === null) {
            $actor->roles()->attach($role);
        } else {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->id,
                'store_id' => $storeId,
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $actor;
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'business_profile' => $channel === 'B2C' ? 'RETAIL_STORE' : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
