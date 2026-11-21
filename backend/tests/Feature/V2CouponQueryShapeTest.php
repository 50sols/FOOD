<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2CouponQueryShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_coupon_get_filters_are_scalar_only_in_both_locales(): void
    {
        $admin = $this->user('v2-4064-super@example.test');
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->actingAs($admin)->post(route('admin.coupons.store'), $this->couponPayload('B2B-4064', 'b2b', null, 'Literal %_! coupon'))
            ->assertRedirect();
        $this->actingAs($admin)->post(route('admin.coupons.store'), $this->couponPayload('B2B-OTHER', 'b2b', null, 'Not a matching coupon'))
            ->assertRedirect();

        $beforeCoupons = DB::table('marketing_coupons')->count();
        $beforeAudit = DB::table('audit_logs')->count();
        $url = route('admin.coupons.index');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin->fresh())->get($url.'?q='.rawurlencode('%_!'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('B2B-4064')
                ->assertDontSee('B2B-OTHER');

            $this->actingAs($admin->fresh())->get($url.'?q=&channel=&status=')->assertOk();
            $this->actingAs($admin->fresh())->get($url.'?channel=unknown&status=unknown')->assertOk();

            foreach ([
                '?q%5B%5D=x',
                '?q%5Bdeep%5D%5B0%5D=x',
                '?channel%5B%5D=b2c',
                '?channel%5Bdeep%5D=b2b',
                '?status%5B%5D=active',
                '?status%5Bdeep%5D=inactive',
                '?q=normal&channel%5B%5D=b2c',
            ] as $malformed) {
                $this->actingAs($admin->fresh())->get($url.$malformed)->assertNotFound();
            }
        }

        $this->assertSame($beforeCoupons, DB::table('marketing_coupons')->count());
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
    }

    public function test_retail_coupon_store_isolation_and_permission_precede_malformed_filter_denial(): void
    {
        $storeA = $this->store('V2-4064-A');
        $storeB = $this->store('V2-4064-B');
        $admin = $this->user('v2-4064-owner@example.test');
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->actingAs($admin)->post(route('admin.coupons.store'), $this->couponPayload('OWN-4064', 'b2c', $storeA, 'Owned promo'))
            ->assertRedirect();
        $this->actingAs($admin)->post(route('admin.coupons.store'), $this->couponPayload('OTHER-4064', 'b2c', $storeB, 'Other store promo'))
            ->assertRedirect();
        $this->actingAs($admin)->post(route('admin.coupons.store'), $this->couponPayload('WHOLESALE-4064', 'b2b', null, 'Wholesale promo'))
            ->assertRedirect();

        $retail = $this->user('v2-4064-retail@example.test');
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $storeA,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleless = $this->user('v2-4064-no-role@example.test');

        $beforeCoupons = DB::table('marketing_coupons')->count();
        $beforeAudit = DB::table('audit_logs')->count();
        $url = route('admin.coupons.index');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $retail->forceFill(['locale' => $locale])->save();

            $this->actingAs($retail->fresh())->get($url)
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('OWN-4064')
                ->assertDontSee('OTHER-4064')
                ->assertDontSee('WHOLESALE-4064');

            $this->actingAs($retail->fresh())->get($url.'?q=OWN')->assertOk()->assertSee('OWN-4064');
            $this->actingAs($retail->fresh())->get($url.'?status%5B%5D=active')->assertNotFound();

            // Effective authorization must happen before malformed filter validation.
            $this->actingAs($roleless)->get($url.'?q%5B%5D=x')->assertForbidden();
            $this->actingAs($roleless)->get($url.'?channel%5B%5D=b2b')->assertForbidden();
        }

        $this->assertSame($beforeCoupons, DB::table('marketing_coupons')->count());
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'advertising_enabled' => true,
            'live_ads_enabled' => false,
            'coupons_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function couponPayload(string $code, string $channel, ?int $storeId, string $nameEn): array
    {
        return [
            'channel' => $channel,
            'store_id' => $storeId,
            'code' => $code,
            'name_ar' => 'خصم',
            'name_en' => $nameEn,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'is_active' => 1,
        ];
    }
}
