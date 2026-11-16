<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2FinanceWorkbenchPanelAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
        // Test-only module certification; production readiness is unchanged.
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest('finance')->version,
        );
    }

    public function test_ar_and_ap_subpanels_preserve_authorized_store_filters_dates_and_locale(): void
    {
        [$actor, $store] = $this->authorizedViewer('FIN-P303-UX-A');

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            foreach ([
                'receivables' => ['documents','settlements','aging'],
                'payables' => ['documents','aging'],
            ] as $tab => $panels) {
                foreach ($panels as $panel) {
                    $query = [
                        'store_id' => $store->id,
                        'tab' => $tab,
                        'finance_panel' => $panel,
                        'status' => 'draft',
                        'date_from' => '2026-01-01',
                        'date_to' => '2026-12-31',
                    ];
                    if ($tab === 'payables') {
                        $query['supplier_reference'] = 'SUPPLIER-P303';
                    }
                    $response = $this->actingAs($actor->fresh())
                        ->get(route('admin.erp.finance.index', $query))
                        ->assertOk()
                        ->assertViewHas('tab', $tab)
                        ->assertSee('dir="'.$direction.'"', false)
                        ->assertSee('data-finance-subpanel="'.$panel.'"', false);

                    $html = html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $this->assertSame(1, substr_count($html, 'data-finance-subpanel="'));
                    $this->assertSame(1, preg_match(
                        '/<nav class="finance-tabs foodex-tabs finance-subtabs"[^>]*data-finance-subtabs[^>]*>(.*?)<\\/nav>/s',
                        $html,
                        $nav,
                    ));
                    $this->assertSame(count($panels), substr_count($nav[1], 'data-finance-subtab="'));
                    $this->assertSame(1, substr_count($nav[1], 'aria-current="page"'));
                    $this->assertStringContainsString('name="finance_panel" value="'.$panel.'"', $html);
                    $this->assertStringContainsString('name="date_from" value="2026-01-01"', $html);
                    $this->assertStringContainsString('name="date_to" value="2026-12-31"', $html);
                    $this->assertStringContainsString('flex-wrap:nowrap', $html);
                    foreach ($panels as $candidate) {
                        if ($candidate !== $panel) {
                            $this->assertStringNotContainsString('data-finance-subpanel="'.$candidate.'"', $html);
                        }

                        $this->assertSame(1, preg_match(
                            '/data-finance-subtab="'.preg_quote($candidate, '/').'"[^>]*href="([^"]+)"/s',
                            $nav[1],
                            $link,
                        ));
                        parse_str((string) parse_url($link[1], PHP_URL_QUERY), $parsed);
                        $this->assertSame($tab, $parsed['tab'] ?? null);
                        $this->assertSame($candidate, $parsed['finance_panel'] ?? null);
                        $this->assertSame((string) $store->id, $parsed['store_id'] ?? null);
                        $this->assertSame('draft', $parsed['status'] ?? null);
                        $this->assertSame('2026-01-01', $parsed['date_from'] ?? null);
                        $this->assertSame('2026-12-31', $parsed['date_to'] ?? null);
                    }
                }
            }
        }
    }

    public function test_invalid_array_and_forged_subpanels_fail_to_safe_documents_without_data_writes(): void
    {
        [$actor, $store] = $this->authorizedViewer('FIN-P303-UX-INVALID');
        $before = [
            DB::table('enterprise_finance_journals')->count(),
            DB::table('enterprise_ar_allocations')->count(),
        ];

        foreach (['receivables', 'payables'] as $tab) {
            foreach ([null, 'journals', 'payments', ['aging'], ['other' => 'settlements']] as $panel) {
                $query = ['store_id' => $store->id, 'tab' => $tab];
                if ($panel !== null) {
                    $query['finance_panel'] = $panel;
                }

                $response = $this->actingAs($actor)
                    ->get(route('admin.erp.finance.index', $query))->assertOk();
                $this->assertSame(1, substr_count($response->getContent(), 'data-finance-subpanel="'));
                $response->assertSee('data-finance-subpanel="documents"', false)
                    ->assertDontSee('data-finance-subpanel="aging"', false)
                    ->assertDontSee('data-finance-subpanel="settlements"', false);
            }
        }

        $this->assertSame($before, [
            DB::table('enterprise_finance_journals')->count(),
            DB::table('enterprise_ar_allocations')->count(),
        ]);
    }

    public function test_panels_cannot_change_store_or_bypass_finance_access_or_module_lifecycle(): void
    {
        [$actor, $store] = $this->authorizedViewer('FIN-P303-UX-ALLOW');
        $other = $this->createStore('FIN-P303-UX-FOREIGN');

        foreach (['receivables', 'payables'] as $tab) {
            foreach (['documents', 'aging'] as $panel) {
                $this->actingAs($actor)->get(route('admin.erp.finance.index', [
                    'store_id' => $other->id,
                    'tab' => $tab,
                    'finance_panel' => $panel,
                ]))->assertNotFound();
            }
        }

        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail());
        $this->actingAs($outsider)->get(route('admin.erp.finance.index', [
            'store_id' => $store->id,
            'tab' => 'receivables',
            'finance_panel' => 'settlements',
        ]))->assertForbidden();

        app(ModuleLifecycleManager::class)->transition(FinanceFoundationService::MODULE, ModuleState::DISABLED);
        $this->actingAs($actor)->get(route('admin.erp.finance.index', [
            'store_id' => $store->id,
            'tab' => 'payables',
            'finance_panel' => 'aging',
        ]))->assertForbidden();
    }

    public function test_gl_links_project_existing_read_permission_not_journal_ids_alone(): void
    {
        $template = file_get_contents(resource_path('views/admin/finance-workbench.blade.php'));
        $this->assertIsString($template);
        $this->assertStringContainsString(
            "@if(\$document->journal_id && (\$access['gl_read'] ?? false))",
            $template,
        );
        $this->assertStringContainsString(
            "@if(\$settlement->journal_id && (\$access['gl_read'] ?? false))",
            $template,
        );
        $this->assertStringContainsString(
            "5 => (bool) (\$access['gl_read'] ?? false)",
            $template,
        );
        $this->assertStringNotContainsString('@if($document->journal_id)<a', $template);
        $this->assertStringNotContainsString('@if($settlement->journal_id)<a', $template);
    }

    /** @return array{User, Store} */
    private function authorizedViewer(string $code): array
    {
        $store = $this->createStore($code);
        $role = Role::query()
            ->where('code', 'B2C_STORE_ADMIN')
            ->where('scope', 'store')
            ->where('is_active', true)
            ->firstOrFail();
        $permission = Permission::query()->firstOrCreate(
            ['code' => 'finance.view'],
            ['name' => 'finance.view'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $context = app(TenantContextResolver::class)->retail($actor, $store->id);
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);

        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(FinanceFoundationService::MODULE)) {
            app(ModuleLifecycleManager::class)->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
            app(ModuleLifecycleManager::class)->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
        }
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            true,
            [
                'finance.foundation.read',
                'finance.gl.read',
                'finance.opening_balances.read',
                'finance.ar.read',
                'finance.ap.read',
                'finance.credit_terms.read',
            ],
            $actor,
        );

        return [$actor, $store];
    }

    private function createStore(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }
}
