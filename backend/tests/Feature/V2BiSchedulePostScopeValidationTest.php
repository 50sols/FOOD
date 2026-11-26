<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/** V2 #4098: deny foreign BI schedule POST scope before unrelated field validation. */
final class V2BiSchedulePostScopeValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Test-only signed workflow fixture; production certification remains fail-closed.
        SyntheticErpWorkflowEvidence::install();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.enterprise_bi',
            app(ModuleRegistry::class)->manifest('enterprise_bi')->version,
        );
        app(ModuleRegistry::class)->syncManifest('enterprise_bi');
        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLED);
    }

    public function test_foreign_entitled_store_denies_malformed_schedule_before_field_validation_in_ar_and_en(): void
    {
        $storeA = $this->store('V2-4098-OWN');
        $storeB = $this->store('V2-4098-FOREIGN');
        $this->entitle($storeA);
        $this->entitle($storeB);
        $actor = $this->analyst($storeA);

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            app()->forgetInstance(AdminNavigation::class);

            $this->actingAs($actor->fresh())
                ->withHeader('Accept', 'application/json')
                ->post(route('admin.erp.bi.schedules.store'), array_merge(
                    $this->payload($storeB),
                    [
                        'from' => 'invalid-date',
                        'to' => 'not-a-date',
                        'measures' => ['invalid-measure'],
                        'interval_minutes' => 'bad-interval',
                        'next_run_at' => 'not-a-date',
                    ],
                ))
                ->assertForbidden();

            // A well-formed foreign-store request is also forbidden.
            $this->actingAs($actor->fresh())
                ->post(route('admin.erp.bi.schedules.store'), $this->payload($storeB))
                ->assertForbidden();

            $this->assertDatabaseCount('enterprise_scheduled_reports', 0);
        }
    }

    public function test_authorized_store_preserves_unprocessable_validation_and_valid_schedule_creation(): void
    {
        $store = $this->store('V2-4098-ALLOWED');
        $this->entitle($store);
        $actor = $this->analyst($store);

        $this->actingAs($actor)
            ->withHeader('Accept', 'application/json')
            ->post(route('admin.erp.bi.schedules.store'), array_merge(
                $this->payload($store),
                ['from' => 'bad-date', 'interval_minutes' => 'bad-interval'],
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from', 'interval_minutes']);

        $this->assertDatabaseCount('enterprise_scheduled_reports', 0);

        $this->actingAs($actor)
            ->post(route('admin.erp.bi.schedules.store'), $this->payload($store))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('enterprise_scheduled_reports', [
            'requested_by' => $actor->id,
            'scope_type' => 'store',
            'scope_id' => $store->id,
            'interval_minutes' => 1440,
        ]);
        $this->assertDatabaseCount('enterprise_scheduled_reports', 1);
    }

    private function store(string $code): Store
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

    private function entitle(Store $store): void
    {
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
            ['reports.read', 'reports.export'],
        );
    }

    private function analyst(Store $store): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'BUSINESS_ANALYST')->firstOrFail());
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor->fresh();
    }

    /** @return array<string, mixed> */
    private function payload(Store $store): array
    {
        return [
            'report' => 'orders.summary',
            'scope_type' => 'store',
            'scope_id' => $store->id,
            'from' => now()->subDay()->toDateString(),
            'to' => now()->toDateString(),
            'dimensions' => ['date'],
            'measures' => ['orders'],
            'interval_minutes' => 1440,
            'next_run_at' => now()->addHour()->format('Y-m-d H:i:s'),
        ];
    }
}
