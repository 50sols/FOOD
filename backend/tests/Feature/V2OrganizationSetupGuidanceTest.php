<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseOrganization;
use App\Models\Store;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class V2OrganizationSetupGuidanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_organization_setup_preview_validates_without_creating_record_or_audit(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $before = DB::table('audit_logs')->count();

        $this->actingAs($admin)->post(route('admin.enterprise-organizations.store'), [
            'code' => 'example_company',
            'name' => 'Example Company',
            'is_active' => '1',
            '_setup_test' => '1',
        ])->assertRedirect()
            ->assertSessionHas('status', __('enterprise_organization.setup_test_passed'))
            ->assertSessionHas('_old_input.name', 'Example Company')
            ->assertSessionMissing('_old_input.password');

        $this->assertDatabaseMissing('enterprise_organizations', ['code' => 'example_company']);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_existing_organization_preview_preserves_record_and_child_hierarchy(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()->where('code', 'foodex_platform')->firstOrFail();
        $before = DB::table('audit_logs')->count();

        $this->actingAs($admin)->patch(route('admin.enterprise-organizations.update', $organization), [
            'name' => 'Unpersisted Preview',
            'code' => $organization->code,
            'is_active' => '0',
            '_setup_test' => '1',
        ])->assertRedirect()
            ->assertSessionHas('status', __('enterprise_organization.setup_test_passed'))
            ->assertSessionHas('_old_input.name', 'Unpersisted Preview');

        $this->assertSame($organization->name, $organization->fresh()->name);
        $this->assertTrue((bool) $organization->fresh()->is_active);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_preview_rejects_invalid_or_duplicate_codes_without_mutation(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $this->actingAs($admin)->post(route('admin.enterprise-organizations.store'), [
            'name' => 'Invalid', 'code' => '../invalid', '_setup_test' => '1',
        ])->assertSessionHasErrors('code');

        $this->actingAs($admin)->post(route('admin.enterprise-organizations.store'), [
            'name' => 'Duplicate', 'code' => 'foodex_platform', '_setup_test' => '1',
        ])->assertSessionHasErrors('code');

        $this->assertDatabaseMissing('enterprise_organizations', ['name' => 'Invalid']);
        $this->assertDatabaseMissing('enterprise_organizations', ['name' => 'Duplicate']);
    }

    public function test_non_admin_cannot_test_or_save_organization_inputs(): void
    {
        $finance = $this->actor('FINANCE', 'en');
        $payload = ['name' => 'Unauthorized', 'code' => 'forbidden', '_setup_test' => '1'];

        $this->actingAs($finance)->post(route('admin.enterprise-organizations.store'), $payload)
            ->assertForbidden();

        unset($payload['_setup_test']);
        $this->actingAs($finance)->post(route('admin.enterprise-organizations.store'), $payload)
            ->assertForbidden();

        $this->assertDatabaseMissing('enterprise_organizations', ['code' => 'forbidden']);
    }

    public function test_bilingual_existing_admin_form_displays_help_unverified_readiness_and_test_save(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');

        $this->actingAs($admin)->get(route('admin.enterprise-organizations.index'))
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('data-v2-organization-setup-readiness="unverified"', false)
            ->assertSee('Test inputs (no save)')
            ->assertSee('Source: use the organization', false);

        $admin->forceFill(['locale' => 'ar'])->save();
        $this->actingAs($admin->fresh())->get(route('admin.enterprise-organizations.index'))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('غير مُتحقق منه')
            ->assertSee('اختبار الإدخال دون حفظ');
    }

    public function test_seeded_default_organization_code_and_activation_are_protected_on_preview_and_save(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_ORGANIZATION)->firstOrFail();
        $before = DB::table('audit_logs')->count();

        foreach ([true, false] as $preview) {
            $payload = [
                'code' => 'changed_platform_code',
                'name' => 'Corrected Organization Display Name',
                'is_active' => '1',
            ];
            if ($preview) {
                $payload['_setup_test'] = 'edit';
            }

            $this->actingAs($admin)->patch(
                route('admin.enterprise-organizations.update', $organization), $payload,
            )->assertSessionHasErrors('code');
        }

        $this->actingAs($admin)->patch(route('admin.enterprise-organizations.update', $organization), [
            'code' => $organization->code,
            'name' => 'Default Organization',
            'is_active' => '0',
            '_setup_test' => 'edit',
        ])->assertSessionHasErrors('is_active');

        $this->assertSame(OrganizationHierarchyService::DEFAULT_ORGANIZATION, $organization->fresh()->code);
        $this->assertTrue((bool) $organization->fresh()->is_active);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_default_business_unit_code_is_guarded_and_display_name_stays_editable(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $unit = EnterpriseBusinessUnit::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT)->firstOrFail();
        $before = DB::table('audit_logs')->count();

        foreach ([true, false] as $preview) {
            $payload = [
                'name' => 'Renamed Business Unit',
                'code' => 'different_unit_code',
                'is_active' => '1',
            ];
            if ($preview) {
                $payload['_setup_test'] = 'edit';
            }
            $this->actingAs($admin)->patch(route('admin.enterprise-business-units.update', $unit), $payload)
                ->assertSessionHasErrors('code');
        }

        $this->actingAs($admin)->patch(route('admin.enterprise-business-units.update', $unit), [
            'name' => 'Renamed Business Unit',
            'code' => $unit->code,
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertSame('Renamed Business Unit', $unit->fresh()->name);
        $this->assertSame(OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT, $unit->fresh()->code);
        $this->assertSame($before + 1, DB::table('audit_logs')->count());
    }

    public function test_renamed_default_hierarchy_remains_usable_for_new_store_without_reseed(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $organization = EnterpriseOrganization::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_ORGANIZATION)->firstOrFail();
        $this->actingAs($admin)->patch(route('admin.enterprise-organizations.update', $organization), [
            'name' => 'Verified Display Name (not legal certification)',
            'code' => $organization->code,
            'is_active' => '1',
        ])->assertRedirect();

        $typeId = DB::table('store_types')->where('code', 'b2b')->value('id');
        $this->assertNotNull($typeId);
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => 'D-DEFAULT-NEW-STORE',
            'name' => 'Disposable Test Store',
            'is_active' => true,
        ]);

        $scope = app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        $this->assertSame($store->getKey(), $scope->store_id);
        $this->assertSame(OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT, $scope->businessUnit->code);
        $this->assertSame('Verified Display Name (not legal certification)', $organization->fresh()->name);
    }

    public function test_direct_service_cannot_bypass_reserved_routing_code_guard(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'ar');
        $organization = EnterpriseOrganization::query()
            ->where('code', OrganizationHierarchyService::DEFAULT_ORGANIZATION)->firstOrFail();

        $this->expectException(ValidationException::class);
        app(\\App\\Services\\EnterpriseOrganizationAdministration::class)->updateOrganization(
            $organization,
            ['code' => 'unprotected_code', 'name' => $organization->name, 'is_active' => true],
            $admin,
        );
    }

    private function actor(string $roleCode, string $locale): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-setup-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
