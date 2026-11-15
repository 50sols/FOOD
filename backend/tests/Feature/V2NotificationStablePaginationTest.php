<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2NotificationStablePaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_equal_published_timestamps_have_stable_descending_id_pagination_in_both_locales(): void
    {
        $customer = $this->customer('v2-pagination-owner@example.test', 'ar');
        $other = $this->customer('v2-pagination-other@example.test', 'en');
        $publishedAt = now()->subMinutes(5)->startOfSecond();

        $created = [];
        foreach (range(1, 5) as $index) {
            $created[] = $this->notification($publishedAt, $index);
        }

        $foreign = $this->notification($publishedAt, 99, $other->id);
        $expected = array_reverse(array_map(static fn (Notification $notification): int => $notification->id, $created));

        Sanctum::actingAs($customer);

        foreach (['ar', 'en'] as $locale) {
            $actual = [];

            foreach ([1, 2, 3] as $pageNumber) {
                $response = $this->getJson('/api/v1/notifications?locale='.$locale.'&per_page=2&page='.$pageNumber)
                    ->assertOk()
                    ->assertJsonPath('meta.total', 5)
                    ->assertJsonPath('meta.current_page', $pageNumber)
                    ->assertJsonPath('meta.per_page', 2);

                $rows = $response->json('data');
                $this->assertCount($pageNumber === 3 ? 1 : 2, $rows);

                foreach ($rows as $row) {
                    $actual[] = (int) $row['id'];
                    $this->assertSame($locale, $row['locale']);
                    $this->assertSame(
                        ($locale === 'en' ? 'English ' : 'عربي ').$row['data']['sequence'],
                        $row['title'],
                    );
                }
            }

            $this->assertSame($expected, $actual);
            $this->assertNotContains($foreign->id, $actual);
        }

        $this->assertDatabaseCount('notification_reads', 0);
    }

    private function customer(string $email, string $locale): User
    {
        $user = User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);

        Customer::query()->create([
            'user_id' => $user->id,
            'type' => 'b2c',
            'name' => $email,
            'email' => $email,
        ]);

        return $user;
    }

    private function notification(CarbonInterface $publishedAt, int $sequence, ?int $recipientId = null): Notification
    {
        return Notification::query()->create([
            'channel' => 'in_app',
            'type' => 'general',
            'title' => 'عربي '.$sequence,
            'body' => 'نص',
            'title_ar' => 'عربي '.$sequence,
            'title_en' => 'English '.$sequence,
            'body_ar' => 'نص',
            'body_en' => 'Body',
            'audience' => $recipientId === null ? 'customer' : 'user',
            'user_id' => $recipientId,
            'app' => 'customer',
            'target_channel' => 'b2c',
            'status' => 'published',
            'published_at' => $publishedAt,
            'data' => ['sequence' => $sequence],
        ]);
    }
}
