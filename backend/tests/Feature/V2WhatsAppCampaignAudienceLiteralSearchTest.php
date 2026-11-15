<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2WhatsAppCampaignAudienceLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        $this->enableCampaigns();
    }

    public function test_customer_picker_matches_literal_ar_en_symbols_email_phone_and_backslash_without_false_positives(): void
    {
        $store = $this->store('B2C', 'WA-V2-LITERAL-PICKER');
        $literal = $this->customer($store, 'b2c', 'عميل%_!مميز', 'literal%_!@example.test', '+96550001121');
        $this->customer($store, 'b2c', 'عميلXYZمميز', 'literalXYZ@example.test', '+96550001122');
        $slash = $this->customer($store, 'b2c', 'Back\Slash', 'slash@example.test', '+96550001123');
        $ordinary = $this->customer($store, 'b2c', 'Ordinary Customer', 'ordinary@example.test', '+96550001124');
        $phone = $this->customer($store, 'b2c', 'Historic Contact', 'historic@example.test', '+96550001125');

        // Simulate a legacy phone stored verbatim: read-only lookups must not
        // interpret its symbols as SQL wildcard operators.
        DB::table('platform_customers')->where('id', $phone)->update(['phone' => '+96550%_!13']);

        $actor = $this->superAdmin();
        $auditBefore = DB::table('audit_logs')->count();
        $customerBefore = DB::table('platform_customers')->count();

        foreach ([
            ['q' => 'عميل%_!', 'expected' => [$literal]],
            ['q' => 'literal%_!', 'expected' => [$literal]],
            ['q' => '50%_!13', 'expected' => [$phone]],
            ['q' => 'Back\Slash', 'expected' => [$slash]],
            ['q' => 'Back\\', 'expected' => [$slash]],
            ['q' => 'عميلXYZ', 'expected' => [DB::table('platform_customers')->where('name', 'عميلXYZمميز')->value('id')]],
            ['q' => 'عميلA_B', 'expected' => []],
            ['q' => 'Ordinary', 'expected' => [$ordinary]],
            ['q' => '50001124', 'expected' => [$ordinary]],
        ] as $case) {
            $response = $this->actingAs($actor)
                ->getJson(route('admin.whatsapp-campaigns.customers', [
                    'q' => $case['q'],
                    'store_id' => $store,
                    'channel' => 'b2c',
                ]))
                ->assertOk();
            $ids = collect($response->json('customers'))->pluck('id')->all();
            $this->assertSame(array_map('intval', $case['expected']), $ids, 'Literal campaign customer query: '.$case['q']);
        }

        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        $this->assertSame($customerBefore, DB::table('platform_customers')->count());
    }

    public function test_campaign_picker_preserves_store_channel_role_and_module_authorization(): void
    {
        $b2b = $this->store('B2B', 'WA-V2-PICKER-B2B');
        $b2c = $this->store('B2C', 'WA-V2-PICKER-B2C');
        $own = $this->customer($b2b, 'b2b', 'Scope_%! Contact', 'own@example.test', '+96550001201');
        $this->customer($b2c, 'b2c', 'Scope_%! Contact', 'foreign@example.test', '+96550001202');

        $supervisor = User::factory()->create(['locale' => 'ar', 'is_active' => true]);
        $supervisor->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_SUPERVISOR')->firstOrFail());

        $this->actingAs($supervisor)
            ->getJson(route('admin.whatsapp-campaigns.customers', ['q' => 'Scope_%!']))
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.id', $own);

        $this->actingAs($supervisor)
            ->getJson(route('admin.whatsapp-campaigns.customers', [
                'q' => 'Scope_%!',
                'store_id' => $b2b,
                'channel' => 'b2c',
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'customers');

        $this->actingAs($supervisor)
            ->getJson(route('admin.whatsapp-campaigns.customers', [
                'q' => 'Scope_%!',
                'store_id' => $b2c,
            ]))->assertNotFound();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)
            ->getJson(route('admin.whatsapp-campaigns.customers', ['q' => 'Scope_%!']))
            ->assertForbidden();

        WhatsAppModuleState::query()->findOrFail(1)->forceFill(['emergency_stop' => true])->save();
        $this->actingAs($supervisor)
            ->getJson(route('admin.whatsapp-campaigns.customers', ['q' => 'Scope_%!']))
            ->assertNotFound();
    }

    private function enableCampaigns(): void
    {
        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'capabilities' => [
                'outbound' => true,
                'campaigns' => true,
                'inbound' => true,
                'agent_inbox' => true,
            ],
            'test_numbers' => [],
        ])->save();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => (int) DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(int $storeId, string $channel, string $name, string $email, string $phone): int
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'locale' => 'en',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);
        $legacyId = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'type' => $channel,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('platform_customers')->insertGetId([
            'user_id' => $user->id,
            'legacy_customer_id' => $legacyId,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'origin_channel' => $channel,
            'origin_store_id' => $storeId,
            'registration_source' => 'test',
            'registered_at' => now(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
