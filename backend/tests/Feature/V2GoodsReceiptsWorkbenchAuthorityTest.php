<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Procurement\GoodsReceiptManager;
use App\Enterprise\Procurement\PurchaseOrderManager;
use App\Enterprise\Procurement\PurchaseRequestManager;
use App\Enterprise\Procurement\SupplierDirectory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ProcurementSecondApprover;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * P242 / #2572: receiving is the real Procurement workbench Receipts tab.
 * Navigation, selected-store authorization and independent manage/post
 * decisions are enforced by the canonical HTTP/service boundaries.
 */
final class V2GoodsReceiptsWorkbenchAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Test-only ERP workflow evidence; never a live certificate.
        SyntheticErpWorkflowEvidence::install();

        config()->set('security.mfa.enforce_privileged', false);
        config()->set('enterprise_module_readiness.certified_versions', []);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_buyer_and_manager_see_real_scoped_receipts_tab_with_distinct_actions_in_both_locales(): void
    {
        $store = $this->store('B2B', 'P242-RECEIVE-WHOLESALE');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $manager = $this->actor('PROCUREMENT_MANAGER');
        $this->enableProcurement();

        foreach ([
            'admin.erp.procurement.index',
            'admin.erp.procurement.receipts.store',
            'admin.erp.procurement.receipts.post',
            'admin.erp.procurement.receipts.cancel',
        ] as $routeName) {
            $this->assertTrue(Route::has($routeName), 'P242 real receipt route missing: '.$routeName);
        }

        $this->assertTrue($buyer->hasPermission('procurement.goods_receipts.view', $store->id));
        $this->assertFalse($buyer->hasPermission('procurement.goods_receipts.manage', $store->id));
        $this->assertFalse($buyer->hasPermission('procurement.goods_receipts.post', $store->id));
        $this->assertTrue($manager->hasPermission('procurement.goods_receipts.view', $store->id));
        $this->assertTrue($manager->hasPermission('procurement.goods_receipts.manage', $store->id));
        $this->assertTrue($manager->hasPermission('procurement.goods_receipts.post', $store->id));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);

            foreach ([$buyer, $manager] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                app()->forgetInstance(AdminNavigation::class);
                $sidebar = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->keyBy('key');

                $this->assertTrue($sidebar->has('erp_procurement'));
                $this->assertSame('admin.erp.procurement.index', $sidebar['erp_procurement']['route']);

                $this->actingAs($actor->fresh())
                    ->get(route('admin.erp.procurement.index', [
                        'tab' => 'receipts', 'store_id' => $store->id,
                    ], false))
                    ->assertOk()
                    ->assertViewHas('activeTab', 'receipts')
                    ->assertViewHas('can', static fn (array $abilities): bool => $abilities['receipt_manage'] === $actor->hasPermission('procurement.goods_receipts.manage', $store->id)
                        && $abilities['receipt_post'] === $actor->hasPermission('procurement.goods_receipts.post', $store->id)
                    )
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee(__('enterprise_procurement.tabs.receipts'));
            }
        }
    }

    public function test_disabled_or_uncertified_procurement_and_unrelated_roles_cannot_open_receiving(): void
    {
        $store = $this->store('B2B', 'P242-DISABLED');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $this->assertFalse($this->hasProcurementLink($buyer));

        $this->actingAs($buyer)
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'receipts', 'store_id' => $store->id,
            ], false))
            ->assertForbidden();

        $this->enableProcurement();

        foreach ([$this->actor('PROCUREMENT_APPROVER'), $this->actor('B2C_DRIVER'), User::factory()->create(['is_active' => true])] as $outsider) {
            $this->actingAs($outsider)
                ->get(route('admin.erp.procurement.index', [
                    'tab' => 'receipts', 'store_id' => $store->id,
                ], false))
                ->assertForbidden();
        }
    }

    public function test_direct_receipt_write_and_post_reject_view_only_buyer_before_record_lookup(): void
    {
        $store = $this->store('B2B', 'P242-MUTATION');
        $this->enableProcurement();
        $buyer = $this->actor('PROCUREMENT_BUYER');

        $payload = [
            'store_id' => $store->id,
            'warehouse_id' => 999999,
            'lines' => [[
                'purchase_order_line_id' => 999999,
                'quantity' => 1,
            ]],
        ];

        $this->actingAs($buyer)
            ->post(route('admin.erp.procurement.receipts.store', 999999, false), $payload)
            ->assertForbidden();
        $this->actingAs($buyer)
            ->post(route('admin.erp.procurement.receipts.post', 999999, false), ['store_id' => $store->id])
            ->assertForbidden();
        $this->actingAs($buyer)
            ->post(route('admin.erp.procurement.receipts.cancel', 999999, false), ['store_id' => $store->id])
            ->assertForbidden();

        $this->assertDatabaseCount('enterprise_goods_receipts', 0);
    }

    public function test_cross_channel_store_and_multirole_explicit_view_deny_fail_closed(): void
    {
        $ownStore = $this->store('B2B', 'P242-OWN');
        $retailStore = $this->store('B2C', 'P242-FOREIGN');
        $this->enableProcurement();

        $buyer = $this->actor('PROCUREMENT_BUYER');
        $buyer->roles()->attach(Role::query()->where('code', 'PROCUREMENT_APPROVER')->firstOrFail());

        $this->actingAs($buyer->fresh())
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'receipts', 'store_id' => $retailStore->id,
            ], false))
            ->assertForbidden();

        $permission = Permission::query()->where('code', 'procurement.goods_receipts.view')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $buyer->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $buyer->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($buyer->fresh())
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'suppliers', 'store_id' => $ownStore->id,
            ], false))
            ->assertOk()
            ->assertViewHas('tabs', static fn (array $tabs): bool => ! collect($tabs)->contains('key', 'receipts'));

        $this->actingAs($buyer->fresh())
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'receipts', 'store_id' => $ownStore->id,
            ], false))
            ->assertForbidden();
    }

    public function test_direct_receipt_service_honors_persisted_permission_deny_on_stale_actor_in_ar_and_en(): void
    {
        $store = $this->store('B2B', 'P135-GRN-STALE-DENY');
        $this->enableProcurement();
        $actor = $this->actor('PROCUREMENT_MANAGER');
        $context = app(TenantContextResolver::class)->wholesale($actor, $store->id);
        $manager = app(GoodsReceiptManager::class);
        $this->assertFalse((bool) $actor->has_permission_overrides);
        $this->assertSame(0, $manager->paginate($actor, $context)->total());

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ([
                'procurement.goods_receipts.view',
                'procurement.goods_receipts.manage',
                'procurement.goods_receipts.post',
            ] as $permissionCode) {
                $permissionId = (int) Permission::query()->where('code', $permissionCode)->value('id');
                $this->assertGreaterThan(0, $permissionId);
                DB::table('user_permission_overrides')->insert([
                    'user_id' => $actor->id,
                    'permission_id' => $permissionId,
                    'scope_key' => 'global',
                    'store_id' => null,
                    'effect' => 'deny',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('users')->where('id', $actor->id)
                    ->update(['has_permission_overrides' => true]);

                // This User was loaded before DENY; the database is now the
                // only authoritative source for effective RBAC restrictions.
                $this->assertFalse((bool) $actor->has_permission_overrides);
                $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
                $auditBefore = DB::table('audit_logs')->count();
                $receiptBefore = DB::table('enterprise_goods_receipts')->count();
                $operations = match ($permissionCode) {
                    'procurement.goods_receipts.view' => [
                        fn () => $manager->paginate($actor, $context),
                        fn () => $manager->detail($actor, $context, 999999),
                    ],
                    'procurement.goods_receipts.manage' => [
                        fn () => $manager->createFromIssuedOrder($actor, $context, 999999, [
                            'lines' => [[
                                'purchase_order_line_id' => 999999,
                                'warehouse_id' => 999999,
                                'quantity' => 1,
                            ]],
                        ]),
                    ],
                    default => [
                        fn () => $manager->post($actor, $context, 999999),
                    ],
                };
                foreach ($operations as $operation) {
                    try {
                        $operation();
                        $this->fail('Persisted GRN permission DENY must reject a stale direct-service actor.');
                    } catch (HttpExceptionInterface $exception) {
                        $this->assertSame(403, $exception->getStatusCode());
                    }
                }

                $this->assertSame($receiptBefore, DB::table('enterprise_goods_receipts')->count());
                $this->assertSame($auditBefore, DB::table('audit_logs')->count());
                DB::table('user_permission_overrides')
                    ->where('user_id', $actor->id)
                    ->where('permission_id', $permissionId)
                    ->delete();
                DB::table('users')->where('id', $actor->id)
                    ->update(['has_permission_overrides' => false]);
                $this->assertFalse((bool) $actor->fresh()->has_permission_overrides);
                $this->assertSame(0, $manager->paginate($actor, $context)->total());
            }
        }
    }

    public function test_receipt_page_rechecks_revocation_after_database_read_in_ar_and_en(): void
    {
        $store = $this->store('B2B', 'P135-GRN-MIDREAD-DENY');
        $this->enableProcurement();
        $actor = $this->actor('PROCUREMENT_MANAGER');
        $context = app(TenantContextResolver::class)->wholesale($actor, $store->id);
        $manager = app(GoodsReceiptManager::class);
        $permission = Permission::query()
            ->where('code', 'procurement.goods_receipts.view')
            ->firstOrFail();

        $shouldRevoke = false;
        $didRevoke = false;
        DB::listen(static function ($event) use ($actor, $permission, &$shouldRevoke, &$didRevoke): void {
            $sql = strtolower(ltrim((string) $event->sql));
            if (! $shouldRevoke || $didRevoke
                || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'enterprise_goods_receipts')) {
                return;
            }

            // This SELECT already passed the legitimate read preflight.
            // The same-connection hook emulates revocation between that
            // preflight and the scoped GRN result's return.
            $didRevoke = true;
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => $permission->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => true]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertFalse((bool) $actor->fresh()->has_permission_overrides);
            $this->assertSame(0, $manager->paginate($actor, $context)->total());
            $auditBefore = DB::table('audit_logs')->count();

            $didRevoke = false;
            $shouldRevoke = true;
            try {
                $manager->paginate($actor, $context);
                $this->fail('GRN list must not return after a persisted mid-read DENY.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            } finally {
                $shouldRevoke = false;
            }

            $this->assertTrue($didRevoke, 'The live GRN SELECT must trigger persisted revocation.');
            $this->assertFalse((bool) $actor->has_permission_overrides);
            $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
            try {
                $manager->paginate($actor, $context);
                $this->fail('Persisted DENY must also reject subsequent GRN reads.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertDatabaseCount('enterprise_goods_receipts', 0);

            DB::table('user_permission_overrides')
                ->where('user_id', $actor->id)
                ->where('permission_id', $permission->id)
                ->delete();
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => false]);

            $this->assertSame(0, $manager->paginate($actor, $context)->total());
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        }
    }

    public function test_receipt_detail_rechecks_persisted_view_deny_after_relation_queries_in_ar_and_en(): void
    {
        $store = $this->store('B2C', 'P135-GRN-DETAIL-REVOKE');
        $this->enableProcurement();
        $actor = $this->actor('PROCUREMENT_MANAGER');
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $context = app(TenantContextResolver::class)->retail($actor, $store->id);

        // Build a real issued PO and GRN through the maker/checker lifecycle.
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'P135-GRN-DETAIL-SUP',
            'name_en' => 'GRN detail source supplier',
        ]);
        $requests = app(PurchaseRequestManager::class);
        $request = $requests->create($actor, $context, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'reason' => 'Verify revoked receipt detail visibility',
            'lines' => [[
                'item_code' => 'P135-DETAIL-SKU',
                'description' => 'Receiving detail test item',
                'quantity' => 2,
                'unit' => 'each',
                'estimated_unit_cost' => 1.250,
            ]],
        ]);
        $requests->submit($actor, $context, $request->id);
        [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
        $requests->approve($checker, $checkerContext, $request->id);
        $orders = app(PurchaseOrderManager::class);
        $order = $orders->createFromApprovedRequest($actor, $context, $request->id);
        $order = $orders->issue($actor, $context, $order->id);
        $warehouse = Warehouse::query()->create([
            'store_id' => $store->id,
            'code' => 'P135-GRN-DETAIL-WH',
            'name' => 'GRN Detail Receiving',
            'is_active' => true,
        ]);
        $manager = app(GoodsReceiptManager::class);
        $receipt = $manager->createFromIssuedOrder($actor, $context, $order->id, [
            'lines' => [[
                'purchase_order_line_id' => $order->lines()->firstOrFail()->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 1,
            ]],
        ]);
        $this->assertSame($receipt->id, $manager->detail($actor, $context, $receipt->id)->id);
        $permission = Permission::query()->where('code', 'procurement.goods_receipts.view')->firstOrFail();

        $inject = false;
        $revoked = false;
        DB::listen(static function ($event) use ($actor, $permission, &$inject, &$revoked): void {
            $sql = strtolower(ltrim((string) $event->sql));
            if (! $inject || $revoked || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'enterprise_goods_receipts')) {
                return;
            }

            // This scoped header SELECT passed the first authorization;
            // persist the revocation before eager related rows are returned.
            $revoked = true;
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => $permission->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => true]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $auditBefore = DB::table('audit_logs')->count();
            $lineBefore = DB::table('enterprise_goods_receipt_lines')->count();
            $revoked = false;
            $inject = true;
            try {
                $manager->detail($actor, $context, $receipt->id);
                $this->fail('A revoked user must not receive hydrated GRN supplier and cost details.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            } finally {
                $inject = false;
            }

            $this->assertTrue($revoked, 'A scoped GRN header SELECT must trigger the persisted DENY.');
            $this->assertFalse((bool) $actor->has_permission_overrides);
            $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
            try {
                $manager->detail($actor, $context, $receipt->id);
                $this->fail('Persisted GRN VIEW DENY must reject a repeated direct detail read.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($lineBefore, DB::table('enterprise_goods_receipt_lines')->count());
            $this->assertDatabaseHas('enterprise_goods_receipts', [
                'id' => $receipt->id,
                'status' => 'draft',
            ]);

            DB::table('user_permission_overrides')
                ->where('user_id', $actor->id)
                ->where('permission_id', $permission->id)
                ->delete();
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => false]);
            $this->assertSame($receipt->id, $manager->detail($actor, $context, $receipt->id)->id);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        }
    }

    private function store(string $channel, string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function actor(string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    private function enableProcurement(): void
    {
        app(ModuleRegistry::class)->syncManifest('procurement');
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
    }

    private function hasProcurementLink(User $actor): bool
    {
        app()->forgetInstance(AdminNavigation::class);

        return collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->contains('key', 'erp_procurement');
    }
}
