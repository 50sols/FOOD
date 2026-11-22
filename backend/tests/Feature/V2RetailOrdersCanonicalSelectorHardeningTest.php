<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P031: canonical Retail Orders GET must validate both explicit store aliases
 * before applying the permission-scoped B2C orders query.
 */
final class V2RetailOrdersCanonicalSelectorHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_pending_orders_fail_closed_on_noncanonical_or_conflicting_store_selectors(): void
    {
        $own = $this->store('B2C', 'P031-ALIAS-OWN');
        $foreign = $this->store('B2C', 'P031-ALIAS-FOREIGN');
        $wholesale = $this->store('B2B', 'P031-ALIAS-WHOLESALE');
        $actor = $this->actor('B2C_STORE_ADMIN', $own);

        $before = [];
        foreach (['orders', 'payments', 'invoices', 'driver_assignments'] as $table) {
            $before[$table] = DB::table($table)->count();
        }

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->saveQuietly();
            $authorized = $this->url(['store_id' => $own]);

            $this->actingAs($actor->fresh())
                ->get($authorized)
                ->assertOk()
                ->assertViewHas('selectedChannel', 'b2c')
                ->assertViewHas('storeIds', [$own])
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor->fresh())
                ->get($this->url(['store_id' => $own, 'store' => $own, 'channel' => 'b2b']))
                ->assertOk()
                ->assertViewHas('selectedChannel', 'b2c')
                ->assertViewHas('storeIds', [$own]);

            foreach ([$foreign, $wholesale] as $forbidden) {
                $this->actingAs($actor->fresh())
                    ->get($this->url(['store_id' => $forbidden]))
                    ->assertNotFound();

                $this->actingAs($actor->fresh())
                    ->get($this->url(['store_id' => $own, 'store' => $forbidden]))
                    ->assertNotFound();
            }

            foreach (['0', '-1', '01', 'not-an-id', '21474836480000000000000'] as $bad) {
                foreach (['store_id', 'store'] as $alias) {
                    $this->actingAs($actor->fresh())
                        ->get($this->url([$alias => $bad]))
                        ->assertNotFound();
                }
            }
        }

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' changed by GET');
        }
    }

    public function test_retail_orders_alias_never_grants_wholesale_or_roleless_access(): void
    {
        $store = $this->store('B2C', 'P031-ALIAS-DENIED');
        $retail = $this->actor('B2C_STORE_ADMIN', $store);
        $wholesale = $this->actor('B2B_ADMIN');
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->actingAs($retail)
            ->get($this->url(['store_id' => $store, 'channel' => 'b2b']))
            ->assertOk()
            ->assertViewHas('selectedChannel', 'b2c');

        foreach ([$wholesale, $roleless] as $actor) {
            $this->actingAs($actor)
                ->get($this->url(['store_id' => $store]))
                ->assertForbidden();
        }
    }

    /** @param array<string, int|string> $query */
    private function url(array $query): string
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
