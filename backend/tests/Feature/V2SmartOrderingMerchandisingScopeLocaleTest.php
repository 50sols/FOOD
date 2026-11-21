<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class V2SmartOrderingMerchandisingScopeLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_merchandising_rejects_out_of_scope_data_in_arabic_and_english_without_writes(): void
    {
        [$admin, $site] = $this->restaurant('MERCH-LOCALE-A');
        [, $foreignSite] = $this->restaurant('MERCH-LOCALE-B');

        $offerUrl = route('admin.smart-ordering.merchandising.offers.store', $site);
        $recommendationUrl = route('admin.smart-ordering.merchandising.recommendations.store', $site);

        foreach (['en', 'ar'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $this->actingAs($admin->fresh());

            foreach ([
                ['promotion_id', 'promotion_out_of_scope'],
                ['marketing_coupon_id', 'coupon_out_of_scope'],
                ['branch_id', 'branch_out_of_scope'],
            ] as [$field, $key]) {
                $this->postJson($offerUrl, [
                    'name' => 'Scoped offer',
                    'type' => 'percentage',
                    $field => 999999999,
                ])
                    ->assertUnprocessable()
                    ->assertJsonPath('errors.'.$field.'.0', __('smart_ordering_merchandising.'.$key));

                $this->assertDatabaseCount('smart_ordering_offer_rules', $locale === 'en' ? 0 : 1);
            }

            $this->postJson($recommendationUrl, [
                'name' => 'Scoped recommendation',
                'placement' => 'cart_upsell',
                'recommended_product_id' => 999999999,
            ])
                ->assertUnprocessable()
                ->assertJsonPath('errors.recommended_product_id.0', __('smart_ordering_merchandising.product_out_of_scope'));
            $this->assertDatabaseCount('smart_ordering_recommendation_rules', 0);

            $this->postJson($offerUrl, [
                'name' => 'Valid scoped offer',
                'type' => 'percentage',
            ])
                ->assertCreated()
                ->assertJsonPath('name', 'Valid scoped offer');

            $expectedCount = $locale === 'en' ? 1 : 2;
            $this->assertDatabaseCount('smart_ordering_offer_rules', $expectedCount);

            $this->postJson(
                route('admin.smart-ordering.merchandising.offers.store', $foreignSite),
                ['name' => 'Cross-store attack', 'type' => 'percentage'],
            )->assertNotFound();
            $this->assertDatabaseCount('smart_ordering_offer_rules', $expectedCount);
        }
    }

    /** @return array{0:User,1:SmartOrderingSite} */
    private function restaurant(string $code): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::query()->create([
            'name' => $code.' Admin',
            'email' => Str::lower($code).'-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->enableRestaurantSaasForTestStore($storeId);
        $site = app(SmartOrderingSiteService::class)->ensureForStore($storeId, $admin);

        return [$admin, $site];
    }
}
