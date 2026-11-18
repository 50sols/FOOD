<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * P135: the ERP Center card must link to the exact same authorized HTTP
 * procurement workbench that enforces each tab, tenant and permission.
 *
 * The signed evidence here is ephemeral and test-only. Production certification
 * is empty until independent G0-G13 source/CI and installed-version acceptance.
 */
final class V2ProcurementErpCenterHttpContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        SyntheticErpWorkflowEvidence::install();
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
    }

    public function test_ready_card_url_is_real_and_supplier_request_order_tabs_obey_each_role_in_ar_and_en(): void
    {
        $store = $this->wholesaleStore('P135-HTTP-CARD-ALLOW');
        $this->enableAndEntitle($store);
        $manager = $this->userWithRole('PROCUREMENT_MANAGER');
        $buyer = $this->userWithRole('PROCUREMENT_BUYER');
        $this->deny($buyer, ['procurement.purchase_orders.view']);

        $this->assertSame('ready', $this->readiness());
        $this->assertTrue($manager->fresh()->hasPermission('procurement.purchase_orders.view', $store->id));
        $this->assertFalse($buyer->fresh()->hasPermission('procurement.purchase_orders.view', $store->id));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);

            foreach ([$manager, $buyer] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $user = $actor->fresh();

                $center = $this->actingAs($user)->get(route('admin.erp.index'));
                $center->assertOk()->assertSee('dir="'.$direction.'"', false);
                $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');

                $this->assertIsArray($card);
                $this->assertSame('available', $card['status']);
                $this->assertSame(
                    route('admin.erp.procurement.index', ['store_id' => $store->id]),
                    $card['url'],
                );
                $center->assertSee('data-erp-key="procurement" data-erp-status="available"', false);

                // Follow the actual Server-produced card URL; it is not an
                // invented front-end link to a route the actor cannot open.
                $landing = $this->actingAs($user)->get($card['url']);
                $landing->assertOk()->assertSee('dir="'.$direction.'"', false);
                $this->assertSame($store->id, $landing->viewData('selectedStoreId'));

                foreach ([
                    'suppliers' => 'procurement.suppliers.view',
                    'requests' => 'procurement.purchase_requests.view',
                    'orders' => 'procurement.purchase_orders.view',
                ] as $tab => $permission) {
                    $allowed = $user->hasPermission($permission, $store->id);
                    $url = route('admin.erp.procurement.index', [
                        'store_id' => $store->id,
                        'tab' => $tab,
                    ]);
                    $response = $this->actingAs($user)->get($url);
                    if ($allowed) {
                        $response->assertOk()->assertSee('dir="'.$direction.'"', false);
                        $this->assertSame($tab, $response->viewData('activeTab'));
                        $this->assertContains($tab, collect($landing->viewData('tabs'))->pluck('key')->all());
                    } else {
                        $response->assertForbidden();
                        $this->assertNotContains($tab, collect($landing->viewData('tabs'))->pluck('key')->all());
                    }
                }
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function deniedStates(): array
    {
        return [
            'uncertified' => ['uncertified'],
            'wrong exact version' => ['wrong_version'],
            'missing signed proof' => ['unsigned'],
            'disabled lifecycle' => ['disabled'],
            'suspended lifecycle' => ['suspended'],
            'revoked store entitlement' => ['revoked_entitlement'],
            'revoked all entry permissions' => ['revoked_permissions'],
        ];
    }

    #[DataProvider('deniedStates')]
    public function test_negative_card_has_no_action_and_direct_tabs_remain_denied(string $reason): void
    {
        $store = $this->wholesaleStore('P135-HTTP-CARD-DENY');
        $admin = $this->enableAndEntitle($store);
        $buyer = $this->userWithRole('PROCUREMENT_BUYER');

        $this->assertSame('ready', $this->readiness());
        match ($reason) {
            'uncertified' => config()->set('enterprise_module_readiness.certified_versions.procurement', null),
            'wrong_version' => config()->set(
                'enterprise_module_readiness.certified_versions.procurement',
                app(ModuleRegistry::class)->manifest('procurement')->version + 1,
            ),
            'unsigned' => config()->set('enterprise_module_readiness.workflow_attestations.procurement', null),
            'disabled', 'suspended' => app(EnterpriseModuleAdministration::class)
                ->perform('procurement', $reason === 'disabled' ? 'disable' : 'suspend', $admin),
            'revoked_entitlement' => app(ModuleEntitlementManager::class)->set(
                'procurement', new EntitlementScope(EntitlementScope::STORE, $store->id), false,
            ),
            'revoked_permissions' => $this->deny($buyer, [
                'procurement.suppliers.view',
                'procurement.purchase_requests.view',
                'procurement.purchase_orders.view',
            ]),
        };

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            $user = $buyer->fresh();

            // A revoked role may also lose access to the ERP Center entirely;
            // neither case may expose an actionable Procurement link.
            $center = $this->actingAs($user)->get(route('admin.erp.index'));
            if ($center->status() === 200) {
                $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
                $this->assertIsArray($card);
                $this->assertNotSame('available', $card['status'], $reason.'/'.$locale);
                $this->assertNull($card['url']);
            } else {
                $center->assertForbidden();
            }

            foreach (['suppliers', 'requests', 'orders'] as $tab) {
                $this->actingAs($user)->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => $tab,
                ]))->assertForbidden();
            }
        }
    }

    public function test_foreign_store_and_retail_to_wholesale_forgery_cannot_follow_available_card(): void
    {
        $home = $this->wholesaleStore('P135-HTTP-CARD-HOME');
        $foreign = $this->wholesaleStore('P135-HTTP-CARD-FOREIGN');
        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P135-HTTP-CARD-RETAIL',
            'name' => 'P135 HTTP retail channel',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($retail);

        $this->enableAndEntitle($home);
        $buyer = $this->userWithRole('PROCUREMENT_BUYER');
        $retailActor = $this->userWithRole('B2C_STORE_ADMIN', $retail);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            $retailActor->forceFill(['locale' => $locale])->save();

            $center = $this->actingAs($buyer->fresh())->get(route('admin.erp.index'));
            $center->assertOk();
            $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertSame('available', $card['status']);
            $this->assertSame(route('admin.erp.procurement.index', ['store_id' => $home->id]), $card['url']);
            $this->actingAs($buyer->fresh())->get($card['url'])->assertOk();

            foreach (['suppliers', 'requests', 'orders'] as $tab) {
                foreach ([$foreign, $retail] as $deniedStore) {
                    $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                        'store_id' => $deniedStore->id,
                        'tab' => $tab,
                    ]))->assertForbidden();
                }
                $this->actingAs($retailActor->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $home->id,
                    'tab' => $tab,
                ]))->assertForbidden();
            }
            $this->actingAs($retailActor->fresh())->get(route('admin.erp.index'))->assertForbidden();
        }
    }

    public function test_archived_role_and_inactive_actor_revoke_both_center_and_workbench_http(): void
    {
        $store = $this->wholesaleStore('P135-HTTP-CARD-ARCHIVED');
        $this->enableAndEntitle($store);
        $manager = $this->userWithRole('PROCUREMENT_MANAGER');
        $inactive = $this->userWithRole('PROCUREMENT_BUYER');
        $this->actingAs($manager->fresh())->get(route('admin.erp.index'))->assertOk();
        $this->actingAs($manager->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id, 'tab' => 'suppliers',
        ]))->assertOk();

        Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail()
            ->forceFill(['archived_at' => now()])->save();
        $inactive->forceFill(['is_active' => false])->save();

        foreach (['ar', 'en'] as $locale) {
            foreach ([$manager, $inactive] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $this->actingAs($actor->fresh())->get(route('admin.erp.index'))->assertForbidden();
                $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id, 'tab' => 'suppliers',
                ]))->assertForbidden();
            }
        }
    }

    private function enableAndEntitle(Store $store): User
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
        );

        return $admin;
    }

    private function readiness(): string
    {
        $registry = app(ModuleRegistry::class);

        return app(ModuleOperationalReadiness::class)->status(
            'procurement',
            $registry->manifest('procurement')->version,
            $registry->state('procurement'),
        );
    }

    private function wholesaleStore(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function userWithRole(string $roleCode, ?Store $store = null): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        if ($store === null) {
            $actor->roles()->attach($role);
        } else {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->id,
                'role_id' => $role->id,
                'store_id' => $store->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $actor->fresh();
    }

    /** @param list<string> $codes */
    private function deny(User $actor, array $codes): void
    {
        foreach ($codes as $code) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }
}
