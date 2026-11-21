<?php

namespace Tests\Feature;

use App\Models\B2bPriceTier;
use App\Models\OperationalLookup;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalLookupService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SystemLookupFilterQueryShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
    }

    public function test_bilingual_valid_scalar_filters_keep_literal_search_and_both_lookup_sources(): void
    {
        $lookup = OperationalLookup::query()->create([
            'type' => OperationalLookupService::PAYMENT_METHOD,
            'code' => 'v2_4052_payment',
            'label_ar' => 'طريقة اختبار %_',
            'label_en' => 'V2-4052-Method%_',
            'sort_order' => 9000,
            'is_active' => true,
        ]);
        $lookalike = OperationalLookup::query()->create([
            'type' => OperationalLookupService::PAYMENT_METHOD,
            'code' => 'v2_4052_lookalike',
            'label_ar' => 'طريقة اختبار بديلة',
            'label_en' => 'V2-4052-MethodAB',
            'sort_order' => 9001,
            'is_active' => true,
        ]);
        $tier = B2bPriceTier::query()->create([
            'code' => 'V24052TIER',
            'name' => 'V2-4052-Tier%_',
            'name_ar' => 'شريحة اختبار %_',
            'name_en' => 'V2-4052-Tier%_',
            'priority' => 9000,
            'is_active' => true,
        ]);
        $before = $this->counts();
        $actor = $this->superAdmin();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($actor->fresh())->get('/admin/operations/lookups?'.http_build_query([
                'type' => 'payment-methods',
                'q' => 'V2-4052-Method%_',
                'status' => 'active',
            ]))->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($lookup->code)
                ->assertDontSee($lookalike->code);

            $this->actingAs($actor->fresh())->get('/admin/operations/lookups?'.http_build_query([
                'type' => 'pricing-tiers',
                'q' => 'V2-4052-Tier%_',
                'status' => 'active',
            ]))->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($tier->code);

            // Preserve existing fallback for an unknown scalar status:
            // it is not an authorization bypass or a typed filter.
            $this->actingAs($actor->fresh())->get('/admin/operations/lookups?'.http_build_query([
                'type' => 'payment-methods',
                'q' => 'V2-4052-Method%_',
                'status' => 'unknown-scalar',
            ]))->assertOk()->assertSee($lookup->code);
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_nested_search_and_status_are_404_for_all_five_tabs_without_writes(): void
    {
        $actor = $this->superAdmin();
        $before = $this->counts();

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            foreach ([
                'payment-operation-types',
                'payment-methods',
                'pricing-tiers',
                'order-statuses',
                'failed-delivery-reasons',
            ] as $type) {
                foreach ([
                    ['q' => ['bad']],
                    ['status' => ['active']],
                    ['q' => ['nested' => ['bad']]],
                    ['status' => ['nested' => ['inactive']]],
                ] as $malformed) {
                    $url = '/admin/operations/lookups?'.http_build_query([
                        'type' => $type,
                    ] + $malformed);

                    $this->actingAs($actor->fresh())->get($url)->assertNotFound();
                }
            }
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_roleless_actor_stays_forbidden_with_valid_and_malformed_filters(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $before = $this->counts();

        $this->actingAs($actor)->get('/admin/operations/lookups?type=payment-methods&q=valid')
            ->assertForbidden();
        $this->actingAs($actor)->get('/admin/operations/lookups?type=pricing-tiers&q%5B0%5D=x')
            ->assertForbidden();

        $this->assertSame($before, $this->counts());
    }

    /** @return array<int, int> */
    private function counts(): array
    {
        return [
            DB::table('operational_lookups')->count(),
            DB::table('b2b_price_tiers')->count(),
            DB::table('audit_logs')->count(),
        ];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
