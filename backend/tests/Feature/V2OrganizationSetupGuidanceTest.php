<?php

namespace Tests\Feature;

use App\Models\EnterpriseOrganization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        ])->assertRedirect()->assertSessionHas('status', __('enterprise_organization.setup_test_passed'));

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
        ])->assertRedirect()->assertSessionHas('status', __('enterprise_organization.setup_test_passed'));

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
            ->assertSee('Where to get the value', false);

        $admin->forceFill(['locale' => 'ar'])->save();
        $this->actingAs($admin->fresh())->get(route('admin.enterprise-organizations.index'))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('غير مُتحقق منه')
            ->assertSee('اختبار الإدخال دون حفظ');
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
