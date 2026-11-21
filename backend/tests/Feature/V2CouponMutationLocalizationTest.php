<?php

namespace Tests\Feature;

use App\Models\MarketingCoupon;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2CouponMutationLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_used_coupon_duplicate_code_and_excess_percentage_reject_in_selected_language_without_writes(): void
    {
        $used = $this->coupon('USED-P233', 1);
        $existing = $this->coupon('DUP-P233', 0);
        $beforeCoupons = DB::table('marketing_coupons')->count();
        $beforeAudits = DB::table('audit_logs')->count();

        $messages = [
            'ar' => [
                'used' => 'لا يمكن حذف كوبون مستخدم؛ أوقف تفعيله بدلاً من ذلك.',
                'duplicate' => 'رمز الكوبون موجود بالفعل في هذا النطاق التجاري.',
                'percentage' => 'لا يمكن أن تتجاوز نسبة الخصم 100٪.',
            ],
            'en' => [
                'used' => 'Used coupons cannot be deleted; deactivate them instead.',
                'duplicate' => 'Coupon code already exists in this business scope.',
                'percentage' => 'Percentage discount cannot exceed 100%.',
            ],
        ];

        foreach ($messages as $locale => $expected) {
            $this->actingAs($this->admin($locale))
                ->withHeader('Accept', 'application/json')
                ->delete(route('admin.coupons.destroy', $used))
                ->assertStatus(409)
                ->assertJsonPath('message', $expected['used']);

            $this->post(route('admin.coupons.store'), $this->payload('DUP-P233', 10))
                ->assertStatus(422)
                ->assertJsonPath('errors.code.0', $expected['duplicate']);

            $this->post(route('admin.coupons.store'), $this->payload('NEW-P233', 101))
                ->assertStatus(422)
                ->assertJsonPath('errors.discount_value.0', $expected['percentage']);
        }

        $this->assertDatabaseHas('marketing_coupons', ['id' => $used->id, 'used_count' => 1]);
        $this->assertDatabaseHas('marketing_coupons', ['id' => $existing->id, 'code' => 'DUP-P233']);
        $this->assertDatabaseMissing('marketing_coupons', ['code' => 'NEW-P233']);
        $this->assertSame($beforeCoupons, DB::table('marketing_coupons')->count());
        $this->assertSame($beforeAudits, DB::table('audit_logs')->count());
    }

    public function test_roleless_actor_cannot_delete_even_unused_coupon(): void
    {
        $coupon = $this->coupon('PROTECTED-P233', 0);
        $actor = User::query()->create([
            'name' => 'Coupon unauthorized',
            'email' => 'v2-p233-denied@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $beforeAudits = DB::table('audit_logs')->count();

        $this->actingAs($actor)
            ->withHeader('Accept', 'application/json')
            ->delete(route('admin.coupons.destroy', $coupon))
            ->assertForbidden();

        $this->assertDatabaseHas('marketing_coupons', ['id' => $coupon->id]);
        $this->assertSame($beforeAudits, DB::table('audit_logs')->count());
    }

    private function admin(string $locale): User
    {
        $user = User::query()->create([
            'name' => 'Coupon admin '.$locale,
            'email' => 'v2-p233-coupon-'.$locale.'@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function coupon(string $code, int $usedCount): MarketingCoupon
    {
        return MarketingCoupon::query()->create([
            ...$this->payload($code, 10),
            'scope_key' => 'b2b',
            'used_count' => $usedCount,
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $code, int $percentage): array
    {
        return [
            'channel' => 'b2b',
            'store_id' => null,
            'code' => $code,
            'name_ar' => 'كوبون اختبار',
            'name_en' => 'Test coupon',
            'discount_type' => 'percentage',
            'discount_value' => $percentage,
            'usage_limit_total' => 100,
            'usage_limit_per_user' => 1,
            'first_order_only' => false,
        ];
    }
}
