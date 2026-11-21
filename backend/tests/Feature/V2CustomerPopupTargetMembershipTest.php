<?php

namespace Tests\Feature;

use App\Models\NotificationCampaign;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2CustomerPopupTargetMembershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_user_targeted_retail_popup_requires_membership_in_the_requested_store_for_reads_and_events(): void
    {
        $firstStore = $this->retailStore('V2-POPUP-MEMBER-A');
        $secondStore = $this->retailStore('V2-POPUP-MEMBER-B');
        $customer = $this->customer();
        $this->addRetailMembership($customer, $firstStore);

        $firstTarget = $this->campaign('b2c', $firstStore, 'user', $customer->id);
        $secondTarget = $this->campaign('b2c', $secondStore, 'user', $customer->id);
        $publicSecond = $this->campaign('b2c', $secondStore, 'all');

        Sanctum::actingAs($customer);

        foreach (['ar', 'en'] as $locale) {
            $headers = ['Authorization' => 'Bearer customer-popup-test-token'];
            $firstUrl = $this->popupUrl('b2c', $firstStore, $locale);

            $this->withHeaders($headers)->getJson($firstUrl)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $firstTarget->id);

            $secondUrl = $this->popupUrl('b2c', $secondStore, $locale);
            $this->withHeaders($headers)->getJson($secondUrl)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $publicSecond->id);

            $this->withHeaders($headers)->postJson(
                "/api/v1/notification-campaign-popups/{$secondTarget->id}/events",
                [
                    'event' => 'impression',
                    'channel' => 'b2c',
                    'store_id' => $secondStore,
                    'locale' => $locale,
                    'install_id' => 'v2-popup-membership-test',
                ],
            )->assertNotFound();
        }

        $this->assertDatabaseCount('notification_campaign_popup_views', 0);

        DB::table('b2c_customers')
            ->where('user_id', $customer->id)
            ->where('store_id', $firstStore)
            ->delete();

        $headers = ['Authorization' => 'Bearer customer-popup-test-token'];
        $this->withHeaders($headers)->getJson($this->popupUrl('b2c', $firstStore, 'en'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeaders($headers)->postJson(
            "/api/v1/notification-campaign-popups/{$firstTarget->id}/events",
            [
                'event' => 'open',
                'channel' => 'b2c',
                'store_id' => $firstStore,
                'locale' => 'en',
                'install_id' => 'v2-popup-membership-test',
            ],
        )->assertNotFound();

        $this->assertDatabaseCount('notification_campaign_popup_views', 0);
    }

    public function test_retail_only_customer_cannot_read_or_record_wholesale_user_targeted_popups(): void
    {
        $store = $this->retailStore('V2-POPUP-RETAIL-ONLY');
        $customer = $this->customer();
        $this->addRetailMembership($customer, $store);

        $privateWholesale = $this->campaign('b2b', null, 'user', $customer->id);
        $publicWholesale = $this->campaign('b2b', null, 'all');

        Sanctum::actingAs($customer);
        $headers = ['Authorization' => 'Bearer customer-popup-test-token'];

        $this->withHeaders($headers)->getJson($this->popupUrl('b2b', null, 'ar'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $publicWholesale->id);

        $this->withHeaders($headers)->postJson(
            "/api/v1/notification-campaign-popups/{$privateWholesale->id}/events",
            [
                'event' => 'dismiss',
                'channel' => 'b2b',
                'locale' => 'ar',
                'install_id' => 'v2-popup-retail-only',
            ],
        )->assertNotFound();

        $this->assertDatabaseCount('notification_campaign_popup_views', 0);
    }

    private function customer(): User
    {
        return User::query()->create([
            'name' => 'Scoped Popup Customer',
            'email' => 'v2-popup-member@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'advertising_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addRetailMembership(User $customer, int $storeId): void
    {
        DB::table('b2c_customers')->insert([
            'user_id' => $customer->id,
            'store_id' => $storeId,
            'name' => $customer->name,
            'email' => $customer->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function campaign(string $channel, ?int $storeId, string $audience, ?int $userId = null): NotificationCampaign
    {
        return NotificationCampaign::query()->create([
            'name' => 'V2 Popup Scope '.$channel.' '.$audience,
            'type' => 'promotion',
            'title_ar' => 'حملة آمنة',
            'title_en' => 'Scoped campaign',
            'body_ar' => 'إشعار آمن',
            'body_en' => 'Scoped notification',
            'audience' => $audience,
            'user_id' => $userId,
            'app' => 'customer',
            'target_channel' => $channel,
            'store_id' => $storeId,
            'delivery_channel' => 'in_app',
            'popup_frequency' => 'once_per_session',
            'schedule_kind' => 'once',
            'timezone' => 'Asia/Kuwait',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'next_run_at' => now(),
            'status' => 'active',
        ]);
    }

    private function popupUrl(string $channel, ?int $storeId, string $locale): string
    {
        $query = http_build_query([
            'channel' => $channel,
            'locale' => $locale,
            'install_id' => 'v2-popup-membership-test',
            ...($storeId === null ? [] : ['store_id' => $storeId]),
        ]);

        return '/api/v1/notification-campaign-popups?'.$query;
    }
}
