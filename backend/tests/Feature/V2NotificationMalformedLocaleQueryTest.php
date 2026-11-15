<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2NotificationMalformedLocaleQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_notification_reads_preserve_requested_and_saved_ar_en_locale(): void
    {
        $customer = $this->customer('v2-notification-locale-owner@example.test', 'en');
        $notification = $this->notification($customer);
        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $notification->id)
            ->assertJsonPath('data.0.locale', 'en')
            ->assertJsonPath('data.0.title', 'English notification');

        $this->getJson('/api/v1/notifications/'.$notification->id)
            ->assertOk()
            ->assertJsonPath('id', $notification->id)
            ->assertJsonPath('locale', 'en');

        foreach (['en' => 'English notification', 'ar' => 'إشعار عربي'] as $locale => $title) {
            $this->getJson('/api/v1/notifications?locale='.$locale)
                ->assertOk()
                ->assertJsonPath('data.0.title', $title)
                ->assertJsonPath('data.0.locale', $locale);
            $this->getJson('/api/v1/notifications/'.$notification->id.'?locale='.$locale)
                ->assertOk()
                ->assertJsonPath('title', $title)
                ->assertJsonPath('locale', $locale);
        }

        $this->assertDatabaseCount('notification_reads', 0);
    }

    public function test_malformed_notification_locale_is_bilingual_validation_error_without_writes(): void
    {
        foreach ([
            ['ar', 'يجب أن تكون لغة الإشعار ar أو en.'],
            ['en', 'Notification language must be ar or en.'],
        ] as [$locale, $message]) {
            $customer = $this->customer('v2-notification-invalid-'.$locale.'@example.test', $locale);
            $notification = $this->notification($customer);
            Sanctum::actingAs($customer);

            $notificationsBefore = DB::table('notifications')->count();
            $readsBefore = DB::table('notification_reads')->count();

            foreach (['?locale[]=en', '?locale[bad]=ar', '?locale=fr', '?locale='] as $query) {
                foreach ([
                    '/api/v1/notifications',
                    '/api/v1/notifications/'.$notification->id,
                ] as $path) {
                    $this->getJson($path.$query)
                        ->assertUnprocessable()
                        ->assertJsonPath('errors.locale.0', $message)
                        ->assertJsonValidationErrors('locale')
                        ->assertDontSee('Array to string conversion', false);
                }
            }

            $this->assertSame($notificationsBefore, DB::table('notifications')->count());
            $this->assertSame($readsBefore, DB::table('notification_reads')->count());
        }
    }

    public function test_foreign_notification_stays_hidden_even_with_malformed_locale(): void
    {
        $owner = $this->customer('v2-notification-foreign-owner@example.test', 'ar');
        $foreign = $this->notification($owner);
        $attacker = $this->customer('v2-notification-foreign-actor@example.test', 'en');

        $this->getJson('/api/v1/notifications/'.$foreign->id.'?locale[]=en')
            ->assertUnauthorized();

        Sanctum::actingAs($attacker);
        $this->getJson('/api/v1/notifications/'.$foreign->id.'?locale[]=en')
            ->assertNotFound();

        $this->getJson('/api/v1/notifications/'.$foreign->id.'?locale=en')
            ->assertNotFound();

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
            'user_id' => $user->getKey(),
            'type' => 'b2c',
            'name' => $email,
            'email' => $email,
        ]);

        return $user;
    }

    private function notification(User $user): Notification
    {
        return Notification::query()->create([
            'channel' => 'in_app',
            'type' => 'general',
            'title' => 'إشعار عربي',
            'body' => 'محتوى عربي',
            'title_ar' => 'إشعار عربي',
            'title_en' => 'English notification',
            'body_ar' => 'محتوى عربي',
            'body_en' => 'English body',
            'audience' => 'user',
            'user_id' => $user->getKey(),
            'app' => 'customer',
            'target_channel' => 'b2c',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'data' => ['source' => 'v2-locale-contract'],
        ]);
    }
}
