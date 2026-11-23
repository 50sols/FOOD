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
use Tests\TestCase;

/** V2 #4094: schedule ownership must be enforced before PATCH validation. */
final class V2BiSchedulePatchOwnershipValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_non_owner_invalid_patch_is_forbidden_in_both_locales_without_mutating_schedule(): void
    {
        $storeA = $this->store('V2-4094-OWNER');
        $storeB = $this->store('V2-4094-OTHER');
        $this->entitle($storeA);
        $this->entitle($storeB);

        $owner = $this->analyst($storeA);
        $other = $this->analyst($storeB);
        $this->actingAs($owner)
            ->post(route('admin.erp.bi.schedules.store'), $this->schedulePayload($storeA))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $schedule = DB::table('enterprise_scheduled_reports')->where('requested_by', $owner->id)->first();
        $this->assertNotNull($schedule);

        foreach (['ar', 'en'] as $locale) {
            $other->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            app()->forgetInstance(AdminNavigation::class);

            $this->actingAs($other->fresh())
                ->withHeader('Accept', 'application/json')
                ->patch(route('admin.erp.bi.schedules.update', ['schedule' => $schedule->id]), [
                    'enabled' => 'bad-boolean',
                    'interval_minutes' => 'bad-interval',
                    'next_run_at' => 'not-a-date',
                ])
                ->assertForbidden();

            $this->assertDatabaseHas('enterprise_scheduled_reports', [
                'id' => $schedule->id,
                'requested_by' => $owner->id,
                'scope_type' => 'store',
                'scope_id' => $storeA->id,
                'enabled' => true,
                'interval_minutes' => 1440,
            ]);
        }

        $this->assertDatabaseCount('enterprise_scheduled_reports', 1);
    }

    public function test_owner_invalid_patch_still_validates_and_valid_patch_updates_one_schedule(): void
    {
        $store = $this->store('V2-4094-ALLOWED');
        $this->entitle($store);
        $owner = $this->analyst($store);

        $this->actingAs($owner)
            ->post(route('admin.erp.bi.schedules.store'), $this->schedulePayload($store))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $schedule = DB::table('enterprise_scheduled_reports')->where('requested_by', $owner->id)->first();
        $this->assertNotNull($schedule);

        $this->actingAs($owner)
            ->withHeader('Accept', 'application/json')
            ->patch(route('admin.erp.bi.schedules.update', ['schedule' => $schedule->id]), [
                'enabled' => 'bad-boolean',
                'interval_minutes' => 'bad-interval',
                'next_run_at' => 'not-a-date',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enabled', 'interval_minutes', 'next_run_at']);

        $this->actingAs($owner)
            ->withHeader('Accept', 'application/json')
            ->patch(route('admin.erp.bi.schedules.update', ['schedule' => $schedule->id + 99999]), [
                'enabled' => 'bad-boolean',
                'interval_minutes' => 'bad-interval',
                'next_run_at' => 'not-a-date',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('enterprise_scheduled_reports', [
            'id' => $schedule->id,
            'enabled' => true,
            'interval_minutes' => 1440,
        ]);

        $this->actingAs($owner)
            ->patch(route('admin.erp.bi.schedules.update', ['schedule' => $schedule->id]), [
                'enabled' => '0',
                'interval_minutes' => 2880,
                'next_run_at' => now()->addHours(3)->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('enterprise_scheduled_reports', [
            'id' => $schedule->id,
            'requested_by' => $owner->id,
            'scope_id' => $store->id,
            'enabled' => false,
            'interval_minutes' => 2880,
        ]);
        $this->assertDatabaseCount('enterprise_scheduled_reports', 1);
    }

    public function test_owner_loses_scope_and_invalid_patch_is_still_forbidden_without_writes(): void
    {
        $storeA = $this->store('V2-4094-FROM');
        $storeB = $this->store('V2-4094-TO');
        $this->entitle($storeA);
        $this->entitle($storeB);
        $owner = $this->analyst($storeA);

        $this->actingAs($owner)
            ->post(route('admin.erp.bi.schedules.store'), $this->schedulePayload($storeA))
            ->assertRedirect();

        $schedule = DB::table('enterprise_scheduled_reports')->where('requested_by', $owner->id)->first();
        $this->assertNotNull($schedule);

        // Historical schedule moved outside the actor's effective store scope.
        DB::table('enterprise_scheduled_reports')->where('id', $schedule->id)->update([
            'scope_id' => $storeB->id,
        ]);

        $this->actingAs($owner)
            ->withHeader('Accept', 'application/json')
            ->patch(route('admin.erp.bi.schedules.update', ['schedule' => $schedule->id]), [
                'enabled' => 'bad-boolean',
                'interval_minutes' => 'bad-interval',
                'next_run_at' => 'not-a-date',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('enterprise_scheduled_reports', [
            'id' => $schedule->id,
            'scope_id' => $storeB->id,
            'enabled' => true,
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
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'BUSINESS_ANALYST')->firstOrFail());

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    /** @return array<string, mixed> */
    private function schedulePayload(Store $store): array
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
