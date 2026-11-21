<?php

namespace Tests\Feature;

use App\Models\LiveAd;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2LiveAdScheduleValidationLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_retail_create_and_update_reject_invalid_schedule_in_arabic_and_english_without_writes(): void
    {
        $storeId = $this->store('V2-LIVE-DATE-A');
        $actor = $this->manager($storeId);

        foreach (['en' => 'End time must be after start time.', 'ar' => 'يجب أن يكون وقت الانتهاء بعد وقت البدء.'] as $locale => $message) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $invalid = $this->payload($storeId, [
                'name' => 'Invalid '.$locale,
                'starts_at' => '2026-12-01 12:00:00',
                'ends_at' => '2026-12-01 10:00:00',
            ]);

            $before = [
                'ads' => DB::table('live_ads')->count(),
                'audit' => DB::table('audit_logs')->count(),
            ];

            $this->actingAs($actor->fresh())
                ->withHeader('Accept-Language', $locale)
                ->postJson(route('admin.live-ads.store'), $invalid)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['ends_at'])
                ->assertJsonPath('errors.ends_at.0', $message);

            $this->assertSame($before['ads'], DB::table('live_ads')->count());
            $this->assertSame($before['audit'], DB::table('audit_logs')->count());

            $this->actingAs($actor->fresh())
                ->post(route('admin.live-ads.store'), $this->payload($storeId, [
                    'name' => 'Valid '.$locale,
                ]))
                ->assertRedirect();

            $ad = LiveAd::query()->where('store_id', $storeId)->where('name', 'Valid '.$locale)->firstOrFail();
            $this->assertSame('b2c', $ad->channel);

            $beforeUpdate = [
                'ads' => DB::table('live_ads')->count(),
                'audit' => DB::table('audit_logs')->count(),
                'title' => (string) $ad->title_en,
                'end' => (string) $ad->getRawOriginal('ends_at'),
            ];

            $this->actingAs($actor->fresh())
                ->withHeader('Accept-Language', $locale)
                ->patchJson(route('admin.live-ads.update', $ad), [
                    ...$invalid,
                    'title_en' => 'Must not be saved',
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['ends_at'])
                ->assertJsonPath('errors.ends_at.0', $message);

            $this->assertSame($beforeUpdate['ads'], DB::table('live_ads')->count());
            $this->assertSame($beforeUpdate['audit'], DB::table('audit_logs')->count());
            $this->assertSame($beforeUpdate['title'], (string) $ad->fresh()->title_en);
            $this->assertSame($beforeUpdate['end'], (string) $ad->fresh()->getRawOriginal('ends_at'));
        }
    }

    public function test_invalid_actor_and_foreign_store_cannot_submit_live_ad_even_with_valid_schedule(): void
    {
        $own = $this->store('V2-LIVE-DATE-OWN');
        $foreign = $this->store('V2-LIVE-DATE-OTHER');
        $manager = $this->manager($own);
        $roleless = User::query()->create([
            'name' => 'Denied Live Ads User',
            'email' => 'v2-live-date-denied@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $baseline = [
            'ads' => DB::table('live_ads')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];

        $this->actingAs($roleless)
            ->postJson(route('admin.live-ads.store'), $this->payload($own))
            ->assertForbidden();

        $this->actingAs($manager)
            ->postJson(route('admin.live-ads.store'), $this->payload($foreign))
            ->assertNotFound();

        $this->assertSame($baseline['ads'], DB::table('live_ads')->count());
        $this->assertSame($baseline['audit'], DB::table('audit_logs')->count());
    }

    /** @return array<string, mixed> */
    private function payload(int $storeId, array $overrides = []): array
    {
        return [
            'channel' => 'b2c',
            'store_id' => $storeId,
            'name' => 'Scheduled Live Ad',
            'title_ar' => 'إعلان مجدول',
            'title_en' => 'Scheduled ad',
            'frequency' => 'once_per_install',
            'priority' => 100,
            'starts_at' => '2026-12-01 10:00:00',
            'ends_at' => '2026-12-01 12:00:00',
            'is_active' => true,
            ...$overrides,
        ];
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'live_ads_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function manager(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Retail Live Ad Schedule Admin',
            'email' => 'v2-live-date-manager-'.$storeId.'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
