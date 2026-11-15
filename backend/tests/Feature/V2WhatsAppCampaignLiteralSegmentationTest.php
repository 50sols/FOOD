<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\CampaignSegmentationService;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2WhatsAppCampaignLiteralSegmentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_literal_wildcards_and_escape_marker_do_not_expand_campaign_recipients_or_store_scope(): void
    {
        $retailA = $this->store('B2C', 'V2-SEG-A');
        $retailB = $this->store('B2C', 'V2-SEG-B');
        $wholesale = $this->store('B2B', 'V2-SEG-W');

        $matching = $this->customer($retailA, 'b2c', 'VIP_!% عميل', '+96550000111', 'vip-a@example.test');
        $falsePositive = $this->customer($retailA, 'b2c', 'VIPX!offer عميل', '+96550000222', 'vip-b@example.test');
        $this->customer($retailB, 'b2c', 'VIP_!% عميل', '+96550000333', 'vip-c@example.test');
        $this->customer($wholesale, 'b2b', 'VIP_!% عميل', '+96550000444', 'vip-d@example.test');
        $this->customer($retailA, 'b2c', 'VIP_!% عميل', '+96550000555', 'vip-e@example.test', false);

        $before = [
            'customers' => DB::table('platform_customers')->count(),
            'consents' => DB::table('whats_app_marketing_consents')->count(),
            'campaigns' => DB::table('whats_app_campaigns')->count(),
        ];
        $scope = ['store_ids' => [$retailA], 'channels' => ['b2c']];

        $this->assertSame([$matching], $this->ids($scope + ['search' => 'VIP_!%']));
        $this->assertSame([$matching], $this->ids($scope + ['search' => '%']));
        $this->assertSame([$matching], $this->ids($scope + ['search' => '_']));
        $this->assertSame([$matching, $falsePositive], $this->ids($scope + ['search' => '!']));
        $this->assertSame([$matching, $falsePositive], $this->ids($scope + ['search' => 'VIP']));
        $this->assertSame([$matching, $falsePositive], $this->ids($scope + ['search' => 'عميل']));
        $this->assertSame([], $this->ids($scope + ['search' => 'VIP_!% missing']));

        $this->assertSame($before['customers'], DB::table('platform_customers')->count());
        $this->assertSame($before['consents'], DB::table('whats_app_marketing_consents')->count());
        $this->assertSame($before['campaigns'], DB::table('whats_app_campaigns')->count());
    }

    public function test_email_phone_and_literal_backslash_preserve_existing_customer_filters(): void
    {
        $retail = $this->store('B2C', 'V2-SEG-FIELDS');
        $backslash = $this->customer($retail, 'b2c', 'Route\\Pilot', '+96551110001', 'sales_team@example.test');
        $this->customer($retail, 'b2c', 'RoutePilot', '+96551110002', 'salesXteam@example.test');
        $scope = ['store_ids' => [$retail], 'channels' => ['b2c']];

        $this->assertSame([$backslash], $this->ids($scope + ['search' => '\\']));
        $this->assertSame([$backslash], $this->ids($scope + ['search' => 'sales_team']));
        $this->assertSame([$backslash], $this->ids($scope + ['search' => '+96551110001']));
        $this->assertSame([$backslash], $this->ids($scope + ['search' => 'Route\\Pilot']));
        $this->assertSame([], $this->ids($scope + ['search' => 'sales%team']));
    }

    /** @param array<string, mixed> $filters
     * @return list<int>
     */
    private function ids(array $filters): array
    {
        return app(CampaignSegmentationService::class)->customers($filters)
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
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

    private function customer(
        int $storeId,
        string $channel,
        string $name,
        string $phone,
        string $email,
        bool $active = true,
    ): int {
        $user = User::factory()->create([
            'name' => $name,
            'is_active' => true,
            'is_platform_customer' => true,
        ]);

        $legacyId = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'type' => $channel,
            'name' => $name,
            'phone' => $phone,
            'email' => $user->email,
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
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
