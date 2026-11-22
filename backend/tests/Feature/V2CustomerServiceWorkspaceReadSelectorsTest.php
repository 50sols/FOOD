<?php

namespace Tests\Feature;

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
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_case_and_page_navigation_fail_closed_after_authorization_and_store_scope_in_both_locales(): void
    {
        $actor = User::query()->create([
            'name' => 'Customer service selector operator',
            'email' => 'v2-customer-service-selectors@example.test',
            'password' => 'password',
            'is_active' => true,
            'locale' => 'en',
        ]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

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

            foreach ([
                '?page[]=2', '?page=invalid', '?page=0',
                '?page=-1', '?page=1.5', '?page=99999999999999999999999',
                '?case_id=invalid', '?case_id=0', '?case_id=-1',
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
                '', '?page=', '?case_id=', '?page=1',
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
