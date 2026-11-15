<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2B2bAccountMissingMappingLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_missing_authoritative_customer_link_is_localized_for_arabic_and_cannot_activate_account(): void
    {
        $this->assertMissingCustomerMappingUsesSavedLocale('ar', 'ربط حساب الجملة بسجل العميل المعتمد غير مكتمل.');
    }

    public function test_missing_authoritative_customer_link_is_localized_for_english_and_cannot_activate_account(): void
    {
        $this->assertMissingCustomerMappingUsesSavedLocale('en', 'B2B customer domain mapping is incomplete.');
    }

    private function assertMissingCustomerMappingUsesSavedLocale(string $locale, string $expected): void
    {
        // Each test method gets its own RefreshDatabase and Sanctum authentication state.
        $admin = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $admin->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $token = $admin->createToken('b2b-mapping-locale')->plainTextToken;
        $email = 'missing-b2b-'.$locale.'@example.test';

        $response = $this->withToken($token)->postJson('/api/v1/admin/b2b/accounts', [
            'name' => 'Orphan Mapping '.$locale,
            'email' => $email,
            'password' => 'password123',
            'company_name' => 'Orphan Mapping Co',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $accountId = (int) $response->json('data.id');
        $customerId = (int) $response->json('data.customer.id');
        $userId = (int) User::query()->where('email', $email)->value('id');
        $this->assertGreaterThan(0, $customerId);
        $this->assertGreaterThan(0, $userId);

        // Simulate a pre-existing broken link without changing the authoritative customer.
        DB::table('b2b_accounts')->where('id', $accountId)
            ->update(['b2b_customer_id' => null]);
        $auditBefore = DB::table('audit_logs')->count();
        $usersBefore = DB::table('users')->count();
        $paymentsBefore = DB::table('payments')->count();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept-Language' => $locale === 'ar' ? 'en' : 'ar',
        ])->patchJson('/api/v1/admin/b2b/accounts/'.$accountId.'/status', [
            'status' => 'active',
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', $expected);

        $this->assertDatabaseHas('b2b_accounts', [
            'id' => $accountId,
            'b2b_customer_id' => null,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('b2b_customers', ['id' => $customerId]);
        $this->assertDatabaseHas('users', ['id' => $userId, 'is_active' => false]);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        $this->assertSame($usersBefore, DB::table('users')->count());
        $this->assertSame($paymentsBefore, DB::table('payments')->count());
    }

    public function test_unprivileged_actor_is_denied_orphan_status_mutation(): void
    {
        $manager = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $manager->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $created = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/admin/b2b/accounts', [
                'name' => 'Scope protected',
                'email' => 'scope-b2b@example.test',
                'password' => 'password123',
                'company_name' => 'Scope Company',
            ])->assertCreated();
        $id = (int) $created->json('data.id');
        DB::table('b2b_accounts')->where('id', $id)->update(['b2b_customer_id' => null]);

        $finance = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $finance->roles()->attach(Role::query()->where('code', 'FINANCE')->firstOrFail());
        $auditBefore = DB::table('audit_logs')->count();

        $this->actingAs($finance, 'sanctum')
            ->patchJson('/api/v1/admin/b2b/accounts/'.$id.'/status', ['status' => 'active'])
            ->assertForbidden();

        $this->assertDatabaseHas('b2b_accounts', ['id' => $id, 'status' => 'pending']);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }
}
