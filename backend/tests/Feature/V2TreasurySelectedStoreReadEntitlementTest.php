<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseCashbox;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

final class V2TreasurySelectedStoreReadEntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        // Intentional test-only version approval, never a deployment certificate.
        config()->set(
            'enterprise_module_readiness.certified_versions.treasury',
            app(ModuleRegistry::class)->manifest('treasury')->version,
        );
        // P135 adds signed operational proof. Use its isolated synthetic fixture
        // only when the original P135 branch is eventually integrated.
        if (class_exists(SyntheticErpWorkflowEvidence::class)) {
            SyntheticErpWorkflowEvidence::install();
        }
    }

    public function test_selected_store_requires_the_actual_tab_read_entitlement_before_sensitive_prefetch_in_both_locales(): void
    {
        [$actor, $storeA, $storeB] = $this->operatorWithTwoStores();
        $this->enableTreasury($actor);
        $grants = app(ModuleEntitlementManager::class);
        $grants->set('treasury', new EntitlementScope(EntitlementScope::STORE, $storeA->id), true, [
            'treasury.receipts.read',
            'treasury.payments.read',
            'treasury.cheques.read',
            'treasury.reconciliation.read',
            'treasury.cash_closing.read',
        ], $actor);
        // Store B deliberately grants Payments only, not a broad Treasury read.
        $grants->set('treasury', new EntitlementScope(EntitlementScope::STORE, $storeB->id), true, [
            'treasury.payments.read',
        ], $actor);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())->get(route('admin.erp.treasury.index', [
                'store_id' => $storeA->id,
                'tab' => 'receipts',
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false);

            $paymentPage = $this->actingAs($actor->fresh())->get(route('admin.erp.treasury.index', [
                'store_id' => $storeB->id,
                'tab' => 'payments',
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false);

            // The UI must project the same selected-store capability matrix as
            // direct HTTP. A Payments-only store must not advertise Treasury
            // Overview, Receipts, Cheques, Reconciliation, or Cash Closing.
            $html = html_entity_decode($paymentPage->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertSame(1, preg_match('/<nav class="foodex-tabs"[^>]*>(.*?)<\\/nav>/s', $html, $nav));
            preg_match_all('/<a href="([^"]+)"[^>]*>/', $nav[1], $links);
            $visibleTabs = [];
            foreach ($links[1] as $link) {
                parse_str((string) parse_url($link, PHP_URL_QUERY), $params);
                $this->assertSame((string) $storeB->id, $params['store_id'] ?? null);
                $visibleTabs[] = $params['tab'] ?? null;
            }
            $this->assertSame(['payments', 'financing', 'guarantees', 'investments'], $visibleTabs);
            $this->assertSame(1, substr_count($nav[1], 'aria-current="page"'));
            $this->assertStringContainsString('flex-wrap:nowrap', $html);
            $this->assertStringNotContainsString('tab=receipts', $nav[1]);
            $this->assertStringNotContainsString('tab=overview', $nav[1]);

            // The existing TreasuryPlanning/GuaranteedContract services deliberately
            // reuse payments.read for these payment-family read-only workspaces.
            // Do not invent another permission or call their authorized 200 a leak.
            foreach (['financing', 'guarantees', 'investments'] as $tab) {
                $this->actingAs($actor->fresh())->get(route('admin.erp.treasury.index', [
                    'store_id' => $storeB->id,
                    'tab' => $tab,
                ]))->assertOk();
            }

            foreach (['overview', 'receipts', 'forecast', 'reports', 'cheques', 'reconciliation', 'cash_closing'] as $tab) {
                $this->actingAs($actor->fresh())->get(route('admin.erp.treasury.index', [
                    'store_id' => $storeB->id,
                    'tab' => $tab,
                ]))->assertForbidden();
            }

            // A denied direct GET must not even query cashbox/bank metadata.
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $this->actingAs($actor->fresh())->get(route('admin.erp.treasury.index', [
                    'store_id' => $storeB->id,
                    'tab' => 'receipts',
                ]))->assertForbidden();
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }

            foreach ($queries as $query) {
                $this->assertSame(0, preg_match(
                    '/\\bfrom\\s+["`]?enterprise_(?:cashboxes|bank_accounts)\\b/i',
                    (string) ($query['query'] ?? ''),
                ), 'Unentitled Treasury GET queried bank or cashbox metadata before rejection.');
            }
        }

        $this->assertDatabaseCount('enterprise_treasury_receipts', 0);
        $this->assertDatabaseCount('enterprise_treasury_payments', 0);
    }

    public function test_receipt_validation_redirect_keeps_selected_store_tab_and_filters_without_writing(): void
    {
        [$actor, $storeA] = $this->operatorWithTwoStores();
        $this->enableTreasury($actor);
        app(ModuleEntitlementManager::class)->set(
            'treasury',
            new EntitlementScope(EntitlementScope::STORE, $storeA->id),
            true,
            ['treasury.receipts.read', 'treasury.receipts.manage'],
            $actor,
        );

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            $url = route('admin.erp.treasury.index', [
                'tab' => 'receipts',
                'store_id' => $storeA->id,
                'status' => 'draft',
                'date_from' => '2026-11-01',
                'date_to' => '2026-11-30',
            ]);

            $this->actingAs($actor->fresh())->from($url)
                ->post(route('admin.erp.treasury.receipts.store'), [
                    'store_id' => $storeA->id,
                    '_operation_key' => 'ui-invalid-receipt-'.$locale,
                    'target_key' => 'cashbox:999999',
                    'amount' => '1.0000', // Four decimal places violate exact 3dp validation.
                    'value_date' => '2026-11-17',
                    'reference' => 'TEST-VALIDATION',
                ])
                ->assertRedirect($url)
                ->assertSessionHasErrors('amount');

            $page = $this->actingAs($actor->fresh())->get($url)
                ->assertOk()->assertSee('dir="'.$direction.'"', false)
                ->assertSee('role="alert"', false);
            $html = html_entity_decode($page->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertSame(1, preg_match('/<nav class="foodex-tabs"[^>]*>(.*?)<\\/nav>/s', $html, $nav));
            $this->assertSame(1, substr_count($nav[1], 'aria-current="page"'));
            $this->assertStringContainsString('tab=receipts', $nav[1]);
            $this->assertStringContainsString('name="date_from"', $html);
            $this->assertStringContainsString('2026-11-01', $html);
        }

        $this->assertDatabaseCount('enterprise_treasury_receipts', 0);
        $this->assertDatabaseCount('enterprise_treasury_payments', 0);
    }

    public function test_cheques_and_cash_closing_read_only_tabs_do_not_render_unentitled_post_actions(): void
    {
        [$actor, $storeA] = $this->operatorWithTwoStores();
        $this->enableTreasury($actor);
        app(ModuleEntitlementManager::class)->set(
            'treasury',
            new EntitlementScope(EntitlementScope::STORE, $storeA->id),
            true,
            ['treasury.cheques.read', 'treasury.cash_closing.read'],
            $actor,
        );

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $cheques = $this->actingAs($actor->fresh())->get(route('admin.erp.treasury.index', [
                'store_id' => $storeA->id,
                'tab' => 'cheques',
            ]))->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertViewHas('canManageCheque', false)
                ->assertViewHas('canApproveCheque', false);
            $cheques->assertDontSee(route('admin.erp.treasury.cheques.store'), false);

            $closing = $this->actingAs($actor->fresh())->get(route('admin.erp.treasury.index', [
                'store_id' => $storeA->id,
                'tab' => 'cash_closing',
            ]))->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertViewHas('canManageCashClosing', false)
                ->assertViewHas('canApproveCashClosing', false);
            $closing->assertDontSee(route('admin.erp.treasury.cash-closing.store'), false);

            $this->actingAs($actor->fresh())->get(route('admin.erp.treasury.index', [
                'store_id' => $storeA->id,
                'tab' => 'payments',
            ]))->assertForbidden();
        }

        $this->assertDatabaseCount('enterprise_treasury_cheques', 0);
        $this->assertDatabaseCount('enterprise_treasury_cash_closings', 0);
        $this->assertDatabaseCount('enterprise_treasury_receipts', 0);
        $this->assertDatabaseCount('enterprise_treasury_payments', 0);
    }

    public function test_explicit_store_finance_deny_still_overrides_a_valid_treasury_read_capability(): void
    {
        [$actor, , $storeB] = $this->operatorWithTwoStores();
        $this->enableTreasury($actor);
        app(ModuleEntitlementManager::class)->set(
            'treasury',
            new EntitlementScope(EntitlementScope::STORE, $storeB->id),
            true,
            ['treasury.payments.read'],
            $actor,
        );

        $url = route('admin.erp.treasury.index', [
            'store_id' => $storeB->id,
            'tab' => 'payments',
        ]);
        $this->actingAs($actor)->get($url)->assertOk();

        $permissionId = (int) DB::table('permissions')->where('code', 'finance.view')->value('id');
        $this->assertGreaterThan(0, $permissionId);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permissionId,
            'scope_key' => 'store:'.$storeB->id,
            'store_id' => $storeB->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($actor->fresh())->get($url)->assertForbidden();
        $this->assertDatabaseCount('enterprise_treasury_payments', 0);
    }

    public function test_store_a_allow_store_b_deny_roleless_and_inactive_retail_role_are_authorized_by_server(): void
    {
        [$actor, $storeA, $storeB] = $this->operatorWithTwoStores();
        $this->enableTreasury($actor);

        $grants = app(ModuleEntitlementManager::class);
        $grants->set('treasury', new EntitlementScope(EntitlementScope::STORE, $storeA->id), true, [
            'treasury.receipts.read',
        ], $actor);
        $grants->set('treasury', new EntitlementScope(EntitlementScope::STORE, $storeB->id), false, null, $actor);

        $urlA = route('admin.erp.treasury.index', ['store_id' => $storeA->id, 'tab' => 'receipts']);
        $urlB = route('admin.erp.treasury.index', ['store_id' => $storeB->id, 'tab' => 'receipts']);

        $unauthorized = User::factory()->create(['is_active' => true]);
        $this->grantPlatformErpAccess($unauthorized);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())->get($urlA)
                ->assertOk()->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($actor->fresh())->get($urlB)->assertForbidden();
            $this->actingAs($unauthorized)->get($urlA)->assertForbidden();
        }

        // The assigned store role is the actual retail scope credential.
        // A separate ERP test-navigation role must not resurrect it.
        $retailRole = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        $retailRole->forceFill(['is_active' => false])->save();

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            $this->actingAs($actor->fresh())->get($urlA)->assertForbidden();
            $this->actingAs($actor->fresh())->get($urlB)->assertForbidden();
        }

        $this->assertDatabaseCount('enterprise_treasury_receipts', 0);
        $this->assertDatabaseCount('enterprise_treasury_payments', 0);
        $this->assertDatabaseCount('enterprise_treasury_reconciliations', 0);
    }

    public function test_successful_receipt_restores_only_same_origin_tab_and_store_filters_in_both_locales(): void
    {
        [$actor, $storeA, $storeB] = $this->operatorWithTwoStores();
        $this->enableTreasury($actor);
        app(ModuleEntitlementManager::class)->set(
            'treasury',
            new EntitlementScope(EntitlementScope::STORE, $storeA->id),
            true,
            ['treasury.receipts.read', 'treasury.receipts.manage'],
            $actor,
        );
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($storeA->id);
        $this->assertNotNull($scope);
        $cashbox = EnterpriseCashbox::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $storeA->id,
            'code' => 'P302-UX-CASH',
            'name' => 'Treasury query context',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            $url = route('admin.erp.treasury.index', [
                'tab' => 'receipts',
                'store_id' => $storeA->id,
                'q' => 'P302-SEARCH',
                'status' => 'draft',
                'target_type' => 'cashbox',
                'date_from' => '2026-11-01',
                'date_to' => '2026-11-30',
                'page' => '5',
                '_operation_key' => 'never-copy-secret',
            ]);
            $page = $this->actingAs($actor->fresh())->get($url)
                ->assertOk()->assertSee('dir="'.$direction.'"', false);
            $html = html_entity_decode($page->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertSame(1, preg_match('/<nav class="foodex-tabs"[^>]*>(.*?)<\\/nav>/s', $html, $nav));
            $this->assertStringContainsString('q=P302-SEARCH', $nav[1]);
            $this->assertStringNotContainsString('_operation_key=', $nav[1]);

            $expected = route('admin.erp.treasury.index', [
                'tab' => 'receipts',
                'store_id' => $storeA->id,
                'q' => 'P302-SEARCH',
                'status' => 'draft',
                'target_type' => 'cashbox',
                'date_from' => '2026-11-01',
                'date_to' => '2026-11-30',
            ]);
            $this->actingAs($actor->fresh())->withHeader('referer', $url)
                ->post(route('admin.erp.treasury.receipts.store'), [
                    'store_id' => $storeA->id,
                    '_operation_key' => 'p302-receipt-query-'.$locale,
                    'target_key' => 'cashbox:'.$cashbox->id,
                    'amount' => '1.125',
                    'value_date' => '2026-11-17',
                    'reference' => 'P302-RECEIPT-'.$locale,
                ])->assertRedirect($expected)->assertSessionHasNoErrors();
            $this->assertDatabaseHas('enterprise_treasury_receipts', [
                'store_id' => $storeA->id,
                'reference' => 'P302-RECEIPT-'.$locale,
                'amount' => '1.125',
                'status' => 'draft',
            ]);
        }

        // A forged Store B Referer is never allowed to change the post-save
        // return scope, even for an actor assigned to both stores.
        $foreign = route('admin.erp.treasury.index', [
            'tab' => 'receipts', 'store_id' => $storeB->id, 'q' => 'LEAK',
        ]);
        $this->actingAs($actor->fresh())->withHeader('referer', $foreign)
            ->post(route('admin.erp.treasury.receipts.store'), [
                'store_id' => $storeA->id,
                '_operation_key' => 'p302-receipt-foreign-return',
                'target_key' => 'cashbox:'.$cashbox->id,
                'amount' => '2.000',
                'value_date' => '2026-11-17',
                'reference' => 'P302-FOREIGN-RETURN',
            ])->assertRedirect(route('admin.erp.treasury.index', [
                'tab' => 'receipts', 'store_id' => $storeA->id,
            ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('enterprise_treasury_receipts', 3);
        $this->assertDatabaseMissing('enterprise_treasury_receipts', ['store_id' => $storeB->id]);
    }

    /** @return array{User, Store, Store} */
    private function operatorWithTwoStores(): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $this->assertGreaterThan(0, $typeId);
        $stores = [];
        foreach (['TREASURY-READ-A', 'TREASURY-READ-B'] as $code) {
            $store = Store::query()->create([
                'store_type_id' => $typeId,
                'code' => $code,
                'name' => $code,
                'is_active' => true,
            ]);
            app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
            $stores[] = $store;
        }

        $role = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        $actor = User::factory()->create([
            'email' => 'p302-read-scope@example.test',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $this->grantPlatformErpAccess($actor);

        foreach ($stores as $store) {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->id,
                'store_id' => $store->id,
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$actor, $stores[0], $stores[1]];
    }

    private function enableTreasury(User $actor): void
    {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled('treasury')) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition('treasury', ModuleState::ENABLING, $actor);
            $lifecycle->transition('treasury', ModuleState::ENABLED, $actor);
        }
    }
}
