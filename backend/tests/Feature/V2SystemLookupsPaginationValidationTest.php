<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SystemLookupsPaginationValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_all_lookup_tabs_validate_explicit_page_after_authorization_and_filter_guards(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'lookups-page-admin@example.test', 'en');
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $url = route('admin.operations.lookups.index');

        $before = [
            'lookups' => DB::table('operational_lookups')->count(),
            'tiers' => DB::table('b2b_price_tiers')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            foreach ([
                'payment-operation-types',
                'payment-methods',
                'pricing-tiers',
                'order-statuses',
                'failed-delivery-reasons',
            ] as $type) {
                $tabUrl = $url.'?type='.$type;

                foreach (['&page[]=2', '&page=invalid', '&page=0', '&page=-1', '&page=1.5', '&page=999999999999999999999'] as $invalid) {
                    $this->actingAs($admin->fresh())
                        ->getJson($tabUrl.$invalid)
                        ->assertUnprocessable();
                }

                foreach (['', '&page=', '&page=1', '&page=2', '&page=999'] as $valid) {
                    $this->actingAs($admin->fresh())
                        ->get($tabUrl.$valid)
                        ->assertOk()
                        ->assertSee('dir="'.$direction.'"', false);
                }
            }

            // Existing authorization and malformed filter 404s take precedence
            // over a malformed page request, avoiding information disclosure.
            $this->actingAs($roleless)
                ->getJson($url.'?type=pricing-tiers&page=0')
                ->assertForbidden();

            foreach (['type[]=pricing-tiers', 'status[]=active', 'q[]=test', 'type=unknown'] as $selector) {
                $this->actingAs($admin->fresh())
                    ->getJson($url.'?'.$selector.'&page=0')
                    ->assertNotFound();
            }
        }

        $this->assertSame($before['lookups'], DB::table('operational_lookups')->count());
        $this->assertSame($before['tiers'], DB::table('b2b_price_tiers')->count());
        $this->assertSame($before['audit'], DB::table('audit_logs')->count());
    }

    private function actor(string $roleCode, string $email, string $locale): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
