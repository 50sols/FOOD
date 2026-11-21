<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2NotificationCenterQueryShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_notification_center_accepts_scalar_filters_and_rejects_nested_queries_in_both_locales(): void
    {
        $admin = $this->user('v2-4069-super@example.test');
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $match = $this->notification('MATCH-4069', 'REF%_!end');
        $this->notification('DECOY-4069', 'REFAB!end');
        $this->notification('PUBLISHED-4069', 'Published record', 'published');

        $beforeNotifications = DB::table('notifications')->count();
        $beforeReads = DB::table('notification_reads')->count();
        $beforeAudit = DB::table('audit_logs')->count();
        $path = route('admin.notifications.index');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin->fresh())->get($path.'?q='.rawurlencode('REF%_!end'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('MATCH-4069')
                ->assertDontSee('DECOY-4069');

            $this->actingAs($admin->fresh())->get($path.'?status=draft')
                ->assertOk()
                ->assertSee('MATCH-4069')
                ->assertDontSee('PUBLISHED-4069');
            $this->actingAs($admin->fresh())->get($path.'?status=unknown')->assertOk();
            $this->actingAs($admin->fresh())->get($path.'?q=&status=&review_notification=')->assertOk();
            $this->actingAs($admin->fresh())->get($path.'?review_notification='.$match->id)->assertOk();

            foreach ([
                '?q%5B%5D=x',
                '?q%5Bdeep%5D%5B0%5D=x',
                '?status%5B%5D=draft',
                '?status%5Bdeep%5D=published',
                '?review_notification%5B%5D='.$match->id,
                '?review_notification%5Bdeep%5D='.$match->id,
                '?q=REF&review_notification%5B%5D='.$match->id,
            ] as $malformed) {
                $this->actingAs($admin->fresh())->get($path.$malformed)->assertNotFound();
            }
        }

        $this->assertSame($beforeNotifications, DB::table('notifications')->count());
        $this->assertSame($beforeReads, DB::table('notification_reads')->count());
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
    }

    public function test_unprivileged_and_retail_actors_remain_forbidden_before_nested_input_checks(): void
    {
        $roleless = $this->user('v2-4069-roleless@example.test');
        $retail = $this->user('v2-4069-retail@example.test');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-4069-RETAIL',
            'name' => 'V2 Notification Retail',
            'is_active' => true,
            'advertising_enabled' => true,
            'coupons_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->notification('PRIVATE-4069', 'Private notification');
        $beforeNotifications = DB::table('notifications')->count();
        $beforeAudit = DB::table('audit_logs')->count();
        $path = route('admin.notifications.index');

        foreach ([$roleless, $retail] as $actor) {
            foreach (['ar', 'en'] as $locale) {
                $actor->forceFill(['locale' => $locale])->save();
                $this->actingAs($actor->fresh())->get($path)->assertForbidden();
                $this->actingAs($actor->fresh())->get($path.'?q%5B%5D=PRIVATE')->assertForbidden();
                $this->actingAs($actor->fresh())->get($path.'?status%5B%5D=draft')->assertForbidden();
                $this->actingAs($actor->fresh())->get($path.'?review_notification%5B%5D=1')->assertForbidden();
            }
        }

        $this->assertSame($beforeNotifications, DB::table('notifications')->count());
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('notification_reads', 0);
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function notification(string $tag, string $titleEn, string $status = 'draft'): Notification
    {
        return Notification::query()->create([
            'channel' => 'in_app',
            'type' => 'general',
            'title' => $tag,
            'body' => $tag,
            'title_ar' => $tag.' عربي',
            'title_en' => $tag.' '.$titleEn,
            'body_ar' => $tag.' محتوى عربي',
            'body_en' => $tag.' '.$titleEn,
            'audience' => 'all',
            'app' => 'dashboard',
            'target_channel' => 'all',
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }
}
