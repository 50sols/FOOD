<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\CampaignReportService;
use App\Domain\WhatsApp\TemplateRegistryService;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2WhatsAppCampaignReportLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
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

    public function test_report_json_and_csv_export_share_literal_ar_en_phone_email_and_backslash_filters(): void
    {
        $store = $this->store('B2C', 'WA-V2-REPORT-LITERAL');
        $foreignStore = $this->store('B2C', 'WA-V2-REPORT-FOREIGN');
        $campaign = $this->campaign([$store, $foreignStore]);

        $literal = $this->customer($store, 'b2c', 'عميل%_!مميز', 'report%_!@example.test', '+96550001301');
        $lookalike = $this->customer($store, 'b2c', 'عميلXYZمميز', 'reportXYZ@example.test', '+96550001302');
        $slash = $this->customer($store, 'b2c', 'Report\Slash', 'slash-report@example.test', '+96550001303');
        $foreign = $this->customer($foreignStore, 'b2c', 'عميل%_!مميز', 'other%_!@example.test', '+96550001304');

        foreach ([$literal, $lookalike, $slash, $foreign] as $id) {
            $phone = (string) DB::table('platform_customers')->where('id', $id)->value('phone');
            DB::table('whats_app_campaign_recipients')->insert([
                'campaign_id' => $campaign,
                'platform_customer_id' => $id,
                'phone' => $phone,
                'snapshot' => json_encode(['contact_type' => 'existing_contact'], JSON_THROW_ON_ERROR),
                'eligibility_status' => 'eligible',
                'send_status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $actor = $this->superAdmin();
        $auditBefore = DB::table('audit_logs')->count();
        $recipientBefore = DB::table('whats_app_campaign_recipients')->count();
        $reports = app(CampaignReportService::class);

        foreach ([
            ['q' => 'عميل%_!', 'expected' => 'عميل%_!مميز'],
            ['q' => 'report%_!', 'expected' => 'عميل%_!مميز'],
            ['q' => 'Report\Slash', 'expected' => 'Report\Slash'],
            ['q' => 'Report\\', 'expected' => 'Report\Slash'],
            ['q' => 'عميلXYZ', 'expected' => 'عميلXYZمميز'],
        ] as $case) {
            $filters = ['q' => $case['q'], 'store_id' => $store, 'channel' => 'b2c'];
            $page = $this->actingAs($actor)
                ->getJson(route('admin.whatsapp-campaigns.report', ['campaign' => $campaign] + $filters))
                ->assertOk()
                ->assertJsonCount(1, 'recipients.data')
                ->assertJsonPath('recipients.data.0.customer_name', $case['expected']);
            $this->assertSame(1, $page->json('recipients.total'));

            $exported = $reports->exportRows($actor, $campaign, $filters);
            $this->assertSame([$case['expected']], $exported->pluck('customer_name')->all());

            $csv = $this->actingAs($actor)
                ->get(route('admin.whatsapp-campaigns.export', ['campaign' => $campaign, 'format' => 'csv'] + $filters))
                ->assertOk();
            $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
            $this->assertStringContainsString($case['expected'], $csv->getContent());
            $this->assertStringNotContainsString('other%_!@example.test', $csv->getContent());
        }

        $this->actingAs($actor)
            ->getJson(route('admin.whatsapp-campaigns.report', [
                'campaign' => $campaign, 'q' => 'عميلA_B', 'store_id' => $store,
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'recipients.data');
        $this->assertSame(0, $reports->exportRows($actor, $campaign, [
            'q' => 'عميلA_B', 'store_id' => $store,
        ])->count());

        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        $this->assertSame($recipientBefore, DB::table('whats_app_campaign_recipients')->count());
    }

    public function test_report_and_export_denies_foreign_roles_and_disabled_campaign_module(): void
    {
        $store = $this->store('B2C', 'WA-V2-REPORT-DENY');
        $campaign = $this->campaign([$store]);
        $this->customer($store, 'b2c', 'Secure_%! Customer', 'secure@example.test', '+96550001401');

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)
            ->getJson(route('admin.whatsapp-campaigns.report', ['campaign' => $campaign]))
            ->assertForbidden();
        $this->actingAs($roleless)
            ->get(route('admin.whatsapp-campaigns.export', ['campaign' => $campaign, 'format' => 'csv']))
            ->assertForbidden();

        // Existing B2B service supervisor may not view a Retail-only campaign.
        $supervisor = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $supervisor->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_SUPERVISOR')->firstOrFail());
        $this->actingAs($supervisor)
            ->getJson(route('admin.whatsapp-campaigns.report', ['campaign' => $campaign, 'q' => 'Secure_%!']))
            ->assertForbidden();
        $this->actingAs($supervisor)
            ->get(route('admin.whatsapp-campaigns.export', ['campaign' => $campaign, 'format' => 'csv']))
            ->assertForbidden();

        WhatsAppModuleState::query()->findOrFail(1)->forceFill(['emergency_stop' => true])->save();
        $this->actingAs($this->superAdmin())
            ->getJson(route('admin.whatsapp-campaigns.report', ['campaign' => $campaign]))
            ->assertNotFound();
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

    private function customer(int $store, string $channel, string $name, string $email, string $phone): int
    {
        $user = User::factory()->create([
            'name' => $name, 'email' => $email, 'is_active' => true,
            'locale' => 'en', 'is_platform_customer' => true,
        ]);
        $legacy = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'type' => $channel, 'name' => $name, 'phone' => $phone, 'email' => $email,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('platform_customers')->insertGetId([
            'user_id' => $user->id, 'legacy_customer_id' => $legacy,
            'name' => $name, 'phone' => $phone, 'email' => $email,
            'origin_channel' => $channel, 'origin_store_id' => $store,
            'registration_source' => 'test', 'registered_at' => now(),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function campaign(array $storeIds): int
    {
        $template = app(TemplateRegistryService::class)->upsert([
            'name' => 'V2 Report Template',
            'provider_name' => 'v2_report_literal_template',
            'locale' => 'en',
            'category' => 'marketing',
            'provider_status' => 'approved',
            'body_preview' => 'Test',
            'is_active' => true,
        ]);

        return (int) DB::table('whats_app_campaigns')->insertGetId([
            'name' => 'Literal Campaign Report',
            'template_id' => $template,
            'status' => 'draft',
            'segment' => json_encode(['store_ids' => $storeIds], JSON_THROW_ON_ERROR),
            'test_mode' => true,
            'test_allowlist' => json_encode([], JSON_THROW_ON_ERROR),
            'batch_size' => 10,
            'per_minute_limit' => 10,
            'max_recipients' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
