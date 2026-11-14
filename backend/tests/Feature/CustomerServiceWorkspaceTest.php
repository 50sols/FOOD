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

class CustomerServiceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_customer_service_workspace_is_permission_aware_and_uses_canonical_destinations(): void
    {
        $storeId = $this->store('B2C', 'CS-WORKSPACE');
        $actor = $this->storeUser($storeId, [
            'customers.view',
            'customers.manage',
            'orders.view',
            'finance.view',
            'field_ops.manage',
            'whatsapp.inbox.view',
        ]);
        $this->customer($storeId, 'b2c', 'Workspace Customer');

        $this->actingAs($actor)
            ->get(route('admin.erp.customer-service'))
            ->assertOk()
            ->assertViewIs('admin.customer-service-workspace')
            ->assertSee(route('admin.customer-360.index'), false)
            ->assertSee(route('admin.operations.orders.index'), false)
            ->assertSee(route('admin.field-operations.overview'), false)
            ->assertSee(route('admin.whatsapp-inbox.index'), false)
            ->assertSee(__('customer_service_workspace.truth_title'))
            ->assertSee(__('customer_service_workspace.dashboard.title'))
            ->assertSee(__('customer_service_workspace.cases.title'))
            ->assertSee('data-pagination-required', false);
    }

    public function test_customer_only_role_does_not_leak_unpermitted_operational_destinations(): void
    {
        $storeId = $this->store('B2C', 'CS-CUSTOMER');
        $actor = $this->storeUser($storeId, ['customers.view']);

        $response = $this->actingAs($actor)
            ->get(route('admin.erp.customer-service'))
            ->assertOk()
            ->assertSee(route('admin.customer-360.index'), false)
            ->assertViewHas('capabilities', function ($capabilities): bool {
                $keys = $capabilities->pluck('key');

                return $keys->contains('customer')
                    && $keys->contains('activity')
                    && ! $keys->contains('delivery')
                    && ! $keys->contains('conversations');
            });

        $response->assertDontSee(route('admin.whatsapp-inbox.index'), false);
    }

    public function test_user_without_customer_service_permissions_is_denied(): void
    {
        $this->store('B2C', 'CS-DENIED');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->actingAs($actor)
            ->get(route('admin.erp.customer-service'))
            ->assertForbidden();
    }

    public function test_case_lifecycle_is_store_scoped_audited_and_supports_feedback(): void
    {
        $storeA = $this->store('B2C', 'CS-A');
        $storeB = $this->store('B2C', 'CS-B');
        $actor = $this->storeUser($storeA, ['customers.view', 'customers.manage']);
        $customerA = $this->customer($storeA, 'b2c', 'Scoped Customer A');
        $customerB = $this->customer($storeB, 'b2c', 'Scoped Customer B');

        $this->actingAs($actor)
            ->post(route('admin.erp.customer-service.cases.store'), [
                'store_id' => $storeA,
                'platform_customer_id' => $customerA->id,
                'type' => 'complaint',
                'priority' => 'high',
                'queue' => 'general',
                'subject' => 'Damaged item complaint',
                'description' => 'Customer reported damaged packaging.',
            ])
            ->assertRedirect();

        $case = DB::table('customer_service_cases')->where('platform_customer_id', $customerA->id)->first();
        $this->assertNotNull($case);
        $this->assertSame($storeA, (int) $case->store_id);
        $this->assertSame('open', $case->status);
        $this->assertSame('high', $case->priority);
        $this->assertNotNull($case->sla_target_at);

        $this->actingAs($actor)
            ->post(route('admin.erp.customer-service.cases.store'), [
                'store_id' => $storeA,
                'platform_customer_id' => $customerB->id,
                'type' => 'other',
                'priority' => 'normal',
                'subject' => 'Foreign customer',
            ])
            ->assertNotFound();

        $this->actingAs($actor)
            ->patch(route('admin.erp.customer-service.cases.update', ['case' => $case->id]), [
                'status' => 'resolved',
                'service_recovery_code' => 'GOODWILL',
                'service_recovery_note' => 'Recovery approved under customer-service policy.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('customer_service_cases', [
            'id' => $case->id,
            'status' => 'resolved',
            'service_recovery_code' => 'GOODWILL',
        ]);

        $this->actingAs($actor)
            ->post(route('admin.erp.customer-service.cases.notes.store', ['case' => $case->id]), [
                'body' => 'Customer accepted the recovery action.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('customer_service_case_notes', [
            'case_id' => $case->id,
            'body' => 'Customer accepted the recovery action.',
            'is_internal' => true,
        ]);

        $this->actingAs($actor)
            ->post(route('admin.erp.customer-service.cases.feedback.store', ['case' => $case->id]), [
                'csat_score' => 5,
                'nps_score' => 9,
                'feedback_context' => 'post_resolution_call',
                'consent' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('customer_service_cases', [
            'id' => $case->id,
            'csat_score' => 5,
            'nps_score' => 9,
            'feedback_context' => 'post_resolution_call',
        ]);

        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_service.case.created']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_service.case.updated']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_service.case.note_added']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_service.case.feedback_recorded']);

        $this->actingAs($actor)
            ->get(route('admin.erp.customer-service.cases.show', ['case' => $case->id]))
            ->assertOk()
            ->assertSee('Damaged item complaint')
            ->assertSee('Customer accepted the recovery action.');

        $this->actingAs($actor)
            ->get(route('admin.erp.customer-service.cases.show', ['case' => 999999]))
            ->assertNotFound();
    }

    public function test_chatbot_case_authority_is_promoted_to_customer_service_cases(): void
    {
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_10_17_160000_promote_customer_service_cases',
        ]);
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('customer_service_cases'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('customer_service_case_notes'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('whats_app_chatbot_cases'));
    }

    public function test_customer_service_reports_are_registered_for_shared_export_engine(): void
    {
        $reports = config('enterprise_reporting.reports');

        foreach ([
            'customer_service.case_sla',
            'customer_service.feedback',
            'customer_service.customer_growth',
            'customer_service.recovery',
            'customer_service.workload',
        ] as $code) {
            $this->assertSame('customer_service_operations', $reports[$code]['read_model']);
            $this->assertSame('reports.view', $reports[$code]['view_permission']);
            $this->assertSame('reports.export', $reports[$code]['export_permission']);
        }
    }

    public function test_workspace_has_recursive_arabic_and_english_translation_parity(): void
    {
        $en = require lang_path('en/customer_service_workspace.php');
        $ar = require lang_path('ar/customer_service_workspace.php');

        $this->assertSame($this->keysRecursive($en), $this->keysRecursive($ar));

        $customerEn = require lang_path('en/customer_360.php');
        $customerAr = require lang_path('ar/customer_360.php');
        $this->assertSame(array_keys($customerEn['service']), array_keys($customerAr['service']));
        $this->assertSame($customerEn['tabs']['service'] !== '', $customerAr['tabs']['service'] !== '');
    }

    /** @return array<string,mixed> */
    private function keysRecursive(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[$key] = is_array($value) ? $this->keysRecursive($value) : true;
        }

        return $result;
    }

    public function test_customer_service_picker_and_case_list_use_literal_scoped_search(): void
    {
        $storeA = $this->store('B2C', 'V2-CS-3643-A');
        $storeB = $this->store('B2C', 'V2-CS-3643-B');
        $actor = $this->storeUser($storeA, ['customers.view', 'customers.manage']);
        $foreignActor = $this->storeUser($storeB, ['customers.view', 'customers.manage']);

        $matching = $this->customer($storeA, 'b2c', 'Service Q%_! Customer');
        $decoy = $this->customer($storeA, 'b2c', 'Service QABC! Customer');
        $foreign = $this->customer($storeB, 'b2c', 'Foreign Q%_! Customer');
        $slash = $this->customer($storeA, 'b2c', 'Slash Customer');
        $email = $this->customer($storeA, 'b2c', 'Email Contact Literal');
        $phone = $this->customer($storeA, 'b2c', 'Phone Contact Literal');
        $arabic = $this->customer($storeA, 'b2c', 'Arabic Customer');

        DB::table('platform_customers')->where('id', $email->id)
            ->update(['email' => 'service%_!@example.test']);
        DB::table('platform_customers')->where('id', $phone->id)
            ->update(['phone' => '777%_!123']);
        DB::table('platform_customers')->where('id', $slash->id)
            ->update(['name' => 'Slash'.chr(92).'Customer']);
        DB::table('platform_customers')->where('id', $arabic->id)
            ->update(['name' => 'عميل عربي مميز']);

        $cases = app(CustomerServiceCaseManager::class);
        $create = static function (User $user, int $storeId, int $customerId, string $subject) use ($cases): void {
            $cases->createCase($user, [
                'store_id' => $storeId,
                'platform_customer_id' => $customerId,
                'type' => 'complaint',
                'priority' => 'high',
                'queue' => 'general',
                'subject' => $subject,
            ]);
        };
        $create($actor, $storeA, $matching->id, 'Incident %_! literal');
        $create($actor, $storeA, $decoy->id, 'Incident ABC! lookalike');
        $create($actor, $storeA, $slash->id, 'Backslash'.chr(92).' incident');
        $create($actor, $storeA, $email->id, 'Email-linked incident');
        $create($actor, $storeA, $phone->id, 'Phone-linked incident');
        $create($actor, $storeA, $arabic->id, 'شكوى عربية للعميل');
        $create($foreignActor, $storeB, $foreign->id, 'Incident %_! foreign');

        $beforeCases = DB::table('customer_service_cases')->count();
        $beforeCustomers = DB::table('platform_customers')->count();
        $beforeAudit = DB::table('audit_logs')->count();

        foreach ([
            ['q' => 'Q%_!', 'locale' => 'en', 'customer' => [$matching->id], 'subject' => ['Incident %_! literal']],
            ['q' => chr(92), 'locale' => 'en', 'customer' => [$slash->id], 'subject' => ['Backslash'.chr(92).' incident']],
            ['q' => 'service%_!', 'locale' => 'en', 'customer' => [$email->id], 'subject' => ['Email-linked incident']],
            ['q' => '777%_!', 'locale' => 'ar', 'customer' => [$phone->id], 'subject' => ['Phone-linked incident']],
            ['q' => 'عميل عربي', 'locale' => 'ar', 'customer' => [$arabic->id], 'subject' => ['شكوى عربية للعميل']],
            ['q' => 'absent%_!', 'locale' => 'en', 'customer' => [], 'subject' => []],
        ] as $case) {
            $actor->forceFill(['locale' => $case['locale']])->save();
            $response = $this->actingAs($actor->fresh())
                ->get(route('admin.erp.customer-service', [
                    'q' => $case['q'],
                    'customer_q' => $case['q'],
                    'store_id' => $storeA,
                    'status' => 'open',
                ]))
                ->assertOk();

            $ids = $response->viewData('customerOptions')->pluck('id')
                ->map(static fn ($id): int => (int) $id)->all();
            $expected = array_map('intval', $case['customer']);
            sort($ids);
            sort($expected);
            $this->assertSame($expected, $ids);
            $this->assertSame(
                $case['subject'],
                $response->viewData('cases')->getCollection()->pluck('subject')->all(),
            );
        }

        $this->assertSame($beforeCases, DB::table('customer_service_cases')->count());
        $this->assertSame($beforeCustomers, DB::table('platform_customers')->count());
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.customer-service', ['store_id' => $storeB, 'q' => 'Q%_!']))
            ->assertNotFound();
        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)
            ->get(route('admin.erp.customer-service', ['q' => 'Q%_!']))
            ->assertForbidden();
    }

    public function test_customer_service_literal_case_search_preserves_pagination_and_filters(): void
    {
        $store = $this->store('B2C', 'V2-CS-3643-PAGE');
        $actor = $this->storeUser($store, ['customers.view', 'customers.manage']);
        $customer = $this->customer($store, 'b2c', 'Case Page Client');
        $cases = app(CustomerServiceCaseManager::class);

        for ($i = 0; $i < 26; $i++) {
            $cases->createCase($actor, [
                'store_id' => $store,
                'platform_customer_id' => $customer->id,
                'type' => 'complaint',
                'priority' => 'high',
                'queue' => 'general',
                'subject' => 'Batch! Case '.(string) $i,
            ]);
        }
        $cases->createCase($actor, [
            'store_id' => $store,
            'platform_customer_id' => $customer->id,
            'type' => 'complaint',
            'priority' => 'high',
            'subject' => 'BatchX Case decoy',
        ]);
        $beforeAudit = DB::table('audit_logs')->count();
        $filters = ['q' => 'Batch!', 'store_id' => $store, 'status' => 'open'];

        $first = $this->actingAs($actor)
            ->get(route('admin.erp.customer-service', $filters))
            ->assertOk()->viewData('cases');

        $this->assertSame(26, $first->total());
        $this->assertCount(25, $first->items());
        parse_str((string) parse_url((string) $first->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('Batch!', $query['q'] ?? null);
        $this->assertSame((string) $store, $query['store_id'] ?? null);
        $this->assertSame('open', $query['status'] ?? null);

        $second = $this->actingAs($actor)
            ->get(route('admin.erp.customer-service', [...$filters, 'page' => 2]))
            ->assertOk()->viewData('cases');
        $this->assertSame(26, $second->total());
        $this->assertCount(1, $second->items());
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
    }

    private function store(string $channel, string $code): int
    {
        $type = (int) DB::table('store_types')->where('code', $channel)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(int $storeId, string $channel, string $name): PlatformCustomer
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => Str::slug($name).'@example.test',
            'locale' => 'en',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);

        $legacyId = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'type' => $channel,
            'name' => $name,
            'phone' => '+96550000000',
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PlatformCustomer::query()->create([
            'user_id' => $user->id,
            'legacy_customer_id' => $legacyId,
            'name' => $name,
            'phone' => '+96550000000',
            'email' => $user->email,
            'origin_channel' => $channel,
            'origin_store_id' => $storeId,
            'registration_source' => 'dashboard',
            'registered_at' => now(),
            'is_active' => true,
        ]);
    }

    private function storeUser(int $storeId, array $codes): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($user);
        $role = Role::query()->create([
            'code' => 'CUSTOMER_SERVICE_TEST_'.strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Customer Service Test',
            'scope' => 'store',
            'is_system' => false,
            'is_active' => true,
        ]);

        $permissionIds = collect($codes)->map(function (string $code): int {
            return (int) Permission::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $code],
            )->id;
        })->all();

        $role->permissions()->attach($permissionIds);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
