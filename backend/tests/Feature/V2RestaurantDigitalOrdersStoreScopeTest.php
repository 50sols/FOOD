<?php

namespace Tests\Feature;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Enterprise\Entitlements\EntitlementScope;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2RestaurantDigitalOrdersStoreScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_operations_selected_store_is_authorized_before_unrelated_filters_ar_en(): void
    {
        $owned = $this->store('V2-P329-PREFLIGHT-OWNED');
        $foreign = $this->store('V2-P329-PREFLIGHT-FOREIGN');
        $this->enableRestaurantSaasForTestStore($owned);
        $this->enableRestaurantSaasForTestStore($foreign);
        $user = $this->retailOperator([$owned]);
        $url = route('admin.smart-ordering.operations.index');
        $beforeOrders = DB::table('orders')->count();
        $beforeAudit = DB::table('audit_logs')->count();
        $originalLocale = app()->getLocale();

        try {
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                $user->forceFill(['locale' => $locale])->saveQuietly();
                app()->setLocale($locale);

                foreach ([
                    '?tab=orders&store_id='.$foreign.'&status=invalid',
                    '?tab=orders&store_id='.$foreign.'&fulfillment_mode=invalid',
                    '?tab=orders&store_id='.$foreign.'&from=invalid',
                    '?tab=invalid&store_id='.$foreign,
                    '?tab=orders&store_id=999999999&status=invalid',
                ] as $foreignQuery) {
                    $this->actingAs($user->fresh())->getJson($url.$foreignQuery)
                        ->assertNotFound();
                }

                foreach ([
                    '?tab=orders&store_id='.$owned.'&status=invalid',
                    '?tab=orders&store_id=invalid&status=invalid',
                    '?tab=orders&store_id%5B%5D='.$owned.'&status=invalid',
                    '?tab=orders&store_id=0&status=invalid',
                ] as $badInput) {
                    $this->actingAs($user->fresh())->getJson($url.$badInput)
                        ->assertUnprocessable();
                }

                $this->actingAs($user->fresh())
                    ->get($url.'?tab=orders&store_id='.$owned)
                    ->assertOk()->assertSee('dir="'.$direction.'"', false);
            }
        } finally {
            app()->setLocale($originalLocale);
        }

        $roleless = User::query()->create([
            'name' => 'V2 P329 Preflight Roleless',
            'email' => 'v2-p329-preflight-roleless@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $this->actingAs($roleless)->getJson($url.'?tab=orders&store_id='.$foreign.'&status=invalid')
            ->assertForbidden();
        $this->assertSame($beforeOrders, DB::table('orders')->count());
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
    }

    public function test_p329_authorized_digital_orders_filter_is_store_scoped_in_both_locales(): void
    {
        $first = $this->store('V2-P329-FIRST');
        $second = $this->store('V2-P329-SECOND');
        $this->enableRestaurantSaasForTestStore($first);
        $this->enableRestaurantSaasForTestStore($second);
        $user = $this->retailOperator([$first, $second]);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $user->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($user)
                ->get(route('admin.smart-ordering.operations.index', ['tab' => 'orders', 'store_id' => $first]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-restaurant-saas-workspace-nav', false)
                ->assertSee('data-workspace-item="orders"', false)
                ->assertSee('name="store_id" value="'.$first.'"', false)
                ->assertSee('store_id='.$first.'&amp;tab=branches', false)
                ->assertViewHas('stores', fn ($stores): bool => collect($stores)
                    ->pluck('id')->map(fn ($id): int => (int) $id)->all() === [$first]);

            $this->actingAs($user)
                ->get(route('admin.smart-ordering.operations.index', ['tab' => 'orders', 'store_id' => $second]))
                ->assertOk()
                ->assertViewHas('stores', fn ($stores): bool => collect($stores)
                    ->pluck('id')->map(fn ($id): int => (int) $id)->all() === [$second]);
        }
    }

    public function test_p329_unassigned_and_disabled_entitlement_store_is_not_a_digital_orders_destination(): void
    {
        $owned = $this->store('V2-P329-OWNED');
        $foreign = $this->store('V2-P329-FOREIGN');
        $disabled = $this->store('V2-P329-DISABLED');
        foreach ([$owned, $foreign, $disabled] as $storeId) {
            $this->enableRestaurantSaasForTestStore($storeId);
        }
        $user = $this->retailOperator([$owned, $disabled]);
        $access = app(RestaurantSaasStoreAccess::class);
        $this->actingAs($user);

        $this->assertFalse($access->canNavigateToStore($user, $foreign, 'orders.view'));
        $this->get(route('admin.smart-ordering.operations.index', ['tab' => 'orders', 'store_id' => $foreign]))
            ->assertNotFound();
        $this->assertStringNotContainsString('data-workspace-item="orders"', $this->workspaceNav($user, $foreign));

        DB::table('enterprise_module_entitlements')
            ->where('module_code', 'smart_ordering')
            ->where('scope_type', EntitlementScope::STORE)
            ->where('scope_id', $disabled)
            ->update(['is_enabled' => false, 'updated_at' => now()]);

        $this->assertFalse($access->canNavigateToStore($user, $disabled, 'orders.view'));
        $this->get(route('admin.smart-ordering.operations.index', ['tab' => 'orders', 'store_id' => $disabled]))
            ->assertNotFound();
        $this->assertStringNotContainsString('data-workspace-item="orders"', $this->workspaceNav($user, $disabled));
        $this->assertStringContainsString('data-workspace-item="orders"', $this->workspaceNav($user, $owned));
    }

    public function test_p329_explicit_store_deny_and_mixed_role_cannot_bypass_orders_boundary(): void
    {
        $allowed = $this->store('V2-P329-ALLOWED');
        $denied = $this->store('V2-P329-DENIED');
        $unassigned = $this->store('V2-P329-UNASSIGNED');
        foreach ([$allowed, $denied, $unassigned] as $id) {
            $this->enableRestaurantSaasForTestStore($id);
        }
        $user = $this->retailOperator([$allowed, $denied]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => (int) DB::table('permissions')->where('code', 'orders.view')->value('id'),
            'scope_key' => 'store:'.$denied,
            'store_id' => $denied,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->forceFill(['has_permission_overrides' => true])->save();

        $access = app(RestaurantSaasStoreAccess::class);
        $this->assertTrue($access->canNavigateToStore($user, $allowed, 'orders.view'));
        $this->assertFalse($access->canNavigateToStore($user, $denied, 'orders.view'));
        $this->assertFalse($access->canNavigateToStore($user, $unassigned, 'orders.view'));

        $this->actingAs($user)
            ->get(route('admin.smart-ordering.operations.index', ['tab' => 'orders', 'store_id' => $allowed]))
            ->assertOk()
            ->assertViewHas('stores', fn ($stores): bool => collect($stores)
                ->pluck('id')->map(fn ($id): int => (int) $id)->all() === [$allowed]);
        foreach ([$denied, $unassigned] as $forbidden) {
            $this->actingAs($user)
                ->get(route('admin.smart-ordering.operations.index', ['tab' => 'orders', 'store_id' => $forbidden]))
                ->assertNotFound();
            $this->assertStringNotContainsString('data-workspace-item="orders"', $this->workspaceNav($user, $forbidden));
        }
    }

    public function test_p329_super_admin_requires_explicit_retail_support_context(): void
    {
        $storeId = $this->store('V2-P329-SUPPORT');
        $this->enableRestaurantSaasForTestStore($storeId);
        $user = User::query()->create([
            'name' => 'V2 P329 Support',
            'email' => 'v2-p329-support@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->actingAs($user)
            ->get(route('admin.smart-ordering.operations.index', ['tab' => 'orders', 'store_id' => $storeId]))
            ->assertForbidden();
        $this->actingAs($user)
            ->get(route('admin.smart-ordering.operations.index', [
                'tab' => 'orders',
                'store_id' => $storeId,
                'support_access' => 1,
            ]))
            ->assertOk()
            ->assertSee('name="support_access" value="1"', false)
            ->assertSee('name="store_id" value="'.$storeId.'"', false);
    }

    private function workspaceNav(User $user, int $storeId): string
    {
        request()->query->set('store_id', (string) $storeId);

        return view('admin._smart-ordering-workspace-nav', ['user' => $user])->render();
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<int> $storeIds */
    private function retailOperator(array $storeIds): User
    {
        $user = User::query()->create([
            'name' => 'V2 Digital Orders Operator',
            'email' => 'v2-p329-operator@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        foreach ($storeIds as $storeId) {
            DB::table('user_store_roles')->insert([
                'user_id' => $user->id,
                'store_id' => $storeId,
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }
}
