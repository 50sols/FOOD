<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2CustomerServiceWorkspaceReadSelectorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_case_and_page_navigation_fail_closed_after_authorization_and_store_scope_in_both_locales(): void
    {
        // Reuse the established tenant-scoped Customer Service operator
        // fixture pattern from V2CustomerServiceCaseQueryShapeTest. A
        // SUPER_ADMIN without a store is not a representative happy path.
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-CS-READ-SELECTORS',
            'name' => 'Customer Service Read Selectors',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        $role = Role::query()->create([
            'code' => 'V2_CS_READ_SELECTORS',
            'name' => 'Scoped Customer Service Read Selectors',
            'scope' => 'store',
            'is_system' => false,
            'is_active' => true,
        ]);
        $permissionIds = collect(['customers.view', 'customers.manage'])
            ->map(static fn (string $code): int => (int) Permission::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $code],
            )->id)
            ->all();
        $role->permissions()->attach($permissionIds);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleless = User::query()->create([
            'name' => 'Customer service selector roleless',
            'email' => 'v2-customer-service-roleless@example.test',
            'password' => 'password',
            'is_active' => true,
            'locale' => 'en',
        ]);
        $url = route('admin.erp.customer-service');
        $before = [
            'cases' => DB::table('customer_service_cases')->count(),
            'notes' => DB::table('customer_service_case_notes')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->saveQuietly();
            app()->setLocale($locale);

            foreach ([
                '?page[]=2', '?page=invalid', '?page=0',
                '?page=-1', '?page=1.5', '?page=99999999999999999999999',
                '?case_id=invalid', '?case_id=-1',
                '?case_id=1.5', '?case_id=99999999999999999999999',
                '?page=1&case_id=invalid', '?page=invalid&case_id=1',
            ] as $invalid) {
                $this->actingAs($actor->fresh())
                    ->getJson($url.$invalid)
                    ->assertUnprocessable();
            }

            // Existing index-only nested ID contract is an authorized 404,
            // rather than a PHP array-to-integer conversion or a page fallback.
            $this->actingAs($actor->fresh())
                ->getJson($url.'?case_id[]=2&page=0')
                ->assertNotFound();

            // Store scope denial precedes unrelated page/case field errors.
            $this->actingAs($actor->fresh())
                ->getJson($url.'?store_id=99999999&page=0')
                ->assertNotFound();

            $this->actingAs($roleless)
                ->getJson($url.'?page=0&case_id=invalid')
                ->assertForbidden();

            foreach ([
                '', '?page=', '?case_id=', '?case_id=0', '?page=1',
                '?page=2', '?page=9999', '?page=1&case_id=',
            ] as $valid) {
                $this->actingAs($actor->fresh())
                    ->get($url.$valid)
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false);
            }

            // The show route uses its route-bound case, never the query ID.
            $this->actingAs($actor->fresh())
                ->getJson(route('admin.erp.customer-service.cases.show', ['case' => 99999999]).'?case_id[]=2')
                ->assertNotFound();
        }

        $this->assertSame($before['cases'], DB::table('customer_service_cases')->count());
        $this->assertSame($before['notes'], DB::table('customer_service_case_notes')->count());
        $this->assertSame($before['audit'], DB::table('audit_logs')->count());
    }
}
