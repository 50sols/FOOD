<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class V2RestaurantIntegrationBranchFilterScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_observability_filters_check_branch_site_scope_before_validation_in_ar_and_en(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $sites = [];
        $branches = [];
        foreach (['A', 'B'] as $suffix) {
            $storeId = (int) DB::table('stores')->insertGetId([
                'store_type_id' => $typeId,
                'code' => 'V2-INT-FILTER-'.$suffix,
                'name' => 'V2 Integration Filter '.$suffix,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->enableRestaurantSaasForTestStore($storeId);
            $sites[$suffix] = SmartOrderingSite::query()->create([
                'public_id' => (string) Str::uuid(),
                'store_id' => $storeId,
                'slug' => 'v2-int-filter-'.strtolower($suffix),
                'runtime_state' => 'ENABLED',
                'master_enabled' => true,
            ]);
            $branches[$suffix] = (int) DB::table('smart_ordering_branches')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'site_id' => $sites[$suffix]->getKey(),
                'code' => 'BRANCH-'.$suffix,
                'name_en' => 'Branch '.$suffix,
                'timezone' => 'Asia/Kuwait',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $admin = User::query()->create([
            'name' => 'Integration Filter Admin',
            'email' => 'v2-integration-filter-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->getKey(),
            'store_id' => $sites['A']->store_id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $route = 'admin.smart-ordering.integrations.index';
        $siteA = $sites['A'];
        $baseline = [
            DB::table('restaurant_saas_integrations')->count(),
            DB::table('restaurant_saas_integration_events')->count(),
            DB::table('restaurant_saas_webhook_subscriptions')->count(),
            DB::table('audit_logs')->count(),
        ];
        $oldLocale = app()->getLocale();
        try {
            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $admin->forceFill(['locale' => $locale])->saveQuietly();
                foreach ([$branches['B'], 99999999] as $outside) {
                    $this->actingAs($admin->fresh())->getJson(route($route, [
                        'site' => $siteA->getKey(),
                        'branch_id' => $outside,
                        'status' => 'invalid-status',
                    ]))->assertNotFound();
                    $this->actingAs($admin->fresh())->getJson(route($route, [
                        'site' => $siteA->getKey(),
                        'branch_id' => $outside,
                        'status' => 'retry',
                    ]))->assertNotFound();
                }
                $this->actingAs($admin->fresh())->getJson(route($route, [
                    'site' => $siteA->getKey(),
                    'branch_id' => $branches['A'],
                    'status' => 'invalid-status',
                ]))->assertUnprocessable()->assertJsonValidationErrors('status');
                // Invalid selectors must stay on the normal validator path (422),
                // never be coerced into an authorization lookup.
                foreach ([0, 'not-a-branch', ['nested']] as $invalidBranch) {
                    $this->actingAs($admin->fresh())->getJson(route($route, [
                        'site' => $siteA->getKey(),
                        'branch_id' => $invalidBranch,
                        'status' => 'retry',
                    ]))->assertUnprocessable()->assertJsonValidationErrors('branch_id');
                }
                $this->actingAs($admin->fresh())->get(route($route, [
                    'site' => $siteA->getKey(),
                    'branch_id' => $branches['A'],
                    'status' => 'retry',
                ]))->assertOk();
                // Site-wide observability remains available without a branch selector.
                $this->actingAs($admin->fresh())->get(route($route, [
                    'site' => $siteA->getKey(),
                    'status' => 'retry',
                ]))->assertOk();
            }
        } finally {
            app()->setLocale($oldLocale);
        }

        $this->assertSame($baseline, [
            DB::table('restaurant_saas_integrations')->count(),
            DB::table('restaurant_saas_integration_events')->count(),
            DB::table('restaurant_saas_webhook_subscriptions')->count(),
            DB::table('audit_logs')->count(),
        ]);
    }
}
