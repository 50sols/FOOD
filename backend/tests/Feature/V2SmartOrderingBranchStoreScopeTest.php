<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class V2SmartOrderingBranchStoreScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_creation_checks_explicit_store_authority_before_body_validation_in_ar_and_en(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $owned = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'V2-BRANCH-SCOPE-OWNED',
            'name' => 'Owned Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreign = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'V2-BRANCH-SCOPE-FOREIGN',
            'name' => 'Foreign Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableRestaurantSaasForTestStore($owned);
        $this->enableRestaurantSaasForTestStore($foreign);

        $admin = User::query()->create([
            'name' => 'Restaurant Branch Admin',
            'email' => 'v2-branch-create-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $owned,
            'role_id' => (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $url = route('admin.smart-ordering.branches.store');
        $before = DB::table('smart_ordering_branches')->count();
        $localeBefore = app()->getLocale();

        try {
            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $admin->forceFill(['locale' => $locale])->saveQuietly();

                $this->actingAs($admin->fresh())->postJson($url, [
                    'store_id' => $foreign,
                    'name' => ['invalid'],
                ])->assertNotFound();
                $this->actingAs($admin->fresh())->postJson($url, [
                    'store_id' => $foreign,
                ])->assertNotFound();
                // Valid branch inputs and a numeric-string store selector still fail tenant scope.
                foreach ([$foreign, (string) $foreign] as $foreignSelector) {
                    $this->actingAs($admin->fresh())->postJson($url, [
                        'store_id' => $foreignSelector,
                        'name' => 'Forbidden Branch',
                        'timezone' => 'Asia/Kuwait',
                        'pickup_lead_minutes' => 0,
                        'max_schedule_days' => 7,
                        'service_charge' => 0,
                    ])->assertNotFound();
                }

                $this->actingAs($admin->fresh())->postJson($url, [
                    'store_id' => $owned,
                    'name' => ['invalid'],
                ])->assertUnprocessable()->assertJsonValidationErrors('name');
                $this->actingAs($admin->fresh())->postJson($url, [
                    'name' => 'Missing Store',
                ])->assertUnprocessable()->assertJsonValidationErrors('store_id');
            }
        } finally {
            app()->setLocale($localeBefore);
        }

        $this->assertSame($before, DB::table('smart_ordering_branches')->count());

        $this->actingAs($admin->fresh())->post($url, [
            'store_id' => $owned,
            'name' => 'Authorized Branch',
            'timezone' => 'Asia/Kuwait',
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'service_charge' => 0,
        ])->assertRedirect();

        $this->assertDatabaseHas('smart_ordering_branches', [
            'name_en' => 'Authorized Branch',
        ]);
        $this->assertSame(1, DB::table('smart_ordering_branches')
            ->join('smart_ordering_sites', 'smart_ordering_sites.id', '=', 'smart_ordering_branches.site_id')
            ->where('smart_ordering_sites.store_id', $owned)
            ->where('smart_ordering_branches.name_en', 'Authorized Branch')
            ->count());
        $this->assertSame(0, DB::table('smart_ordering_branches')
            ->join('smart_ordering_sites', 'smart_ordering_sites.id', '=', 'smart_ordering_branches.site_id')
            ->where('smart_ordering_sites.store_id', $foreign)
            ->count());
    }
}
