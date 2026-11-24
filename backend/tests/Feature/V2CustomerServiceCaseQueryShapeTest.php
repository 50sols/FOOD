<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerServiceCaseManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V2 #4080: case selection is an authorized scalar identity, never an
 * array-coerced GET selector that changes the selected record silently.
 */
final class V2CustomerServiceCaseQueryShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_case_index_keeps_scalar_and_route_case_selection_but_rejects_nested_selectors(): void
    {
        $storeId = $this->store('V2-CS-4080-OWN');
        $operator = $this->storeOperator($storeId);
        $caseId = $this->createCase($operator, $storeId, 'V2 4080 scalar case');
        $beforeCases = DB::table('customer_service_cases')->count();
        $beforeAudits = DB::table('audit_logs')->count();

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $operator->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            foreach ([
                [],
                ['case_id' => ''],
                ['case_id' => '0'],
            ] as $filters) {
                $this->actingAs($operator->fresh())
                    ->get(route('admin.erp.customer-service', $filters))
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false);
            }

            $this->actingAs($operator->fresh())
                ->get(route('admin.erp.customer-service', ['case_id' => (string) $caseId]))
                ->assertOk()
                ->assertViewHas('selectedCase', static fn ($case): bool => $case !== null)
                ->assertSee('dir="'.$direction.'"', false);

            // An explicit path ID is authoritative; the incidental GET query
            // does not replace it or change the authorized case selection.
            $this->actingAs($operator->fresh())
                ->get(route('admin.erp.customer-service.cases.show', [
                    'case' => $caseId,
                    'case_id' => ['ignored' => $caseId + 1],
                ]))
                ->assertOk()
                ->assertViewHas('selectedCase', static fn ($case): bool => $case !== null);

            foreach ([
                ['case_id' => [$caseId]],
                ['case_id' => ['deep' => $caseId]],
                ['case_id' => ['deep' => ['id' => $caseId]]],
            ] as $invalid) {
                $this->actingAs($operator->fresh())
                    ->get(route('admin.erp.customer-service', $invalid))
                    ->assertNotFound();
            }
        }

        $this->assertSame($beforeCases, DB::table('customer_service_cases')->count());
        $this->assertSame($beforeAudits, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('customer_service_cases', [
            'id' => $caseId,
            'store_id' => $storeId,
            'status' => 'open',
        ]);
    }

    public function test_roleless_authorization_precedes_nested_selector_rejection_and_foreign_cases_remain_hidden(): void
    {
        $ownStore = $this->store('V2-CS-4080-ONE');
        $foreignStore = $this->store('V2-CS-4080-TWO');
        $operator = $this->storeOperator($ownStore);
        $foreignOperator = $this->storeOperator($foreignStore);
        $foreignCaseId = $this->createCase($foreignOperator, $foreignStore, 'Foreign store case');
        $beforeCases = DB::table('customer_service_cases')->count();
        $beforeAudits = DB::table('audit_logs')->count();

        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->actingAs($roleless)
            ->get(route('admin.erp.customer-service', [
                'case_id' => ['deep' => $foreignCaseId],
            ]))
            ->assertForbidden();

        $this->actingAs($operator)
            ->get(route('admin.erp.customer-service', ['case_id' => $foreignCaseId]))
            ->assertNotFound();

        $this->actingAs($operator)
            ->get(route('admin.erp.customer-service', ['case_id' => ['deep' => $foreignCaseId]]))
            ->assertNotFound();

        $this->assertSame($beforeCases, DB::table('customer_service_cases')->count());
        $this->assertSame($beforeAudits, DB::table('audit_logs')->count());
    }

    private function store(string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeOperator(int $storeId): User
    {
        $operator = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($operator);

        $role = Role::query()->create([
            'code' => 'V2_CS_4080_'.strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Customer Service Case Test',
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
            'user_id' => $operator->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $operator;
    }

    private function createCase(User $operator, int $storeId, string $subject): int
    {
        $customerUser = User::factory()->create([
            'name' => 'Case Selector Customer',
            'email' => 'v2-cs-4080-'.Str::lower(Str::random(8)).'@example.test',
            'locale' => 'en',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);
        $legacyId = (int) DB::table('customers')->insertGetId([
            'user_id' => $customerUser->id,
            'type' => 'b2c',
            'name' => $customerUser->name,
            'phone' => '+96550000000',
            'email' => $customerUser->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customer = PlatformCustomer::query()->create([
            'user_id' => $customerUser->id,
            'legacy_customer_id' => $legacyId,
            'name' => $customerUser->name,
            'phone' => '+96550000000',
            'email' => $customerUser->email,
            'origin_channel' => 'b2c',
            'origin_store_id' => $storeId,
            'registration_source' => 'dashboard',
            'registered_at' => now(),
            'is_active' => true,
        ]);
        $case = app(CustomerServiceCaseManager::class)->createCase($operator, [
            'store_id' => $storeId,
            'platform_customer_id' => $customer->id,
            'type' => 'complaint',
            'priority' => 'high',
            'queue' => 'general',
            'subject' => $subject,
        ]);

        return (int) $case->getKey();
    }
}
