<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2DashboardPreviewSessionStoreLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_missing_store_session_422_is_bilingual_and_creates_no_session_or_audit(): void
    {
        $storeId = $this->retailStore('V2-3752-OWN');
        $foreignStoreId = $this->retailStore('V2-3752-OTHER');
        $admin = $this->storeAdmin($storeId);
        $target = $this->user('Session Target', 'v2-3752-target@example.test');
        $before = $this->previewWriteCounts();
        $request = [
            'target_user_id' => $target->id,
            'target_type' => 'customer',
            'channel' => 'b2c',
        ];

        foreach ([
            'ar' => 'يتطلب إنشاء جلسة معاينة لمتجر التجزئة تحديد متجر.',
            'en' => 'Retail preview requires a store.',
        ] as $locale => $message) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin)
                ->postJson(route('admin.app-preview.sessions.store'), $request)
                ->assertUnprocessable()
                ->assertJsonPath('message', $message);

            $this->assertSame($before, $this->previewWriteCounts());
        }

        $this->actingAs($admin)
            ->postJson(route('admin.app-preview.sessions.store'), $request + [
                'store_id' => $foreignStoreId,
            ])
            ->assertForbidden();

        $roleless = $this->user('No Preview Role', 'v2-3752-roleless@example.test');
        $this->actingAs($roleless)
            ->postJson(route('admin.app-preview.sessions.store'), $request + [
                'store_id' => $storeId,
            ])
            ->assertForbidden();

        $this->assertSame($before, $this->previewWriteCounts());
    }

    public function test_wholesale_wrong_principal_session_422_is_bilingual_without_side_effects(): void
    {
        $principalStoreId = app(WholesalePrincipal::class)->storeId();
        $admin = $this->user('Wholesale Session Admin', 'v2-3752-wholesale-admin@example.test');
        $admin->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $target = $this->user('Wholesale Session Target', 'v2-3752-wholesale-target@example.test');
        $before = $this->previewWriteCounts();

        foreach ([
            'ar' => 'يجب أن تستخدم جلسة معاينة الجملة المتجر الرئيسي المعتمد فقط.',
            'en' => 'Wholesale preview uses the canonical principal store.',
        ] as $locale => $message) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin)
                ->postJson(route('admin.app-preview.sessions.store'), [
                    'target_user_id' => $target->id,
                    'target_type' => 'customer',
                    'channel' => 'b2b',
                    'store_id' => $principalStoreId + 100000,
                ])
                ->assertUnprocessable()
                ->assertJsonPath('message', $message);

            $this->assertSame($before, $this->previewWriteCounts());
        }
    }

    /** @return array{sessions:int,events:int,audit:int} */
    private function previewWriteCounts(): array
    {
        return [
            'sessions' => DB::table('app_preview_sessions')->count(),
            'events' => DB::table('app_preview_invalidation_events')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeAdmin(int $storeId): User
    {
        $admin = $this->user('Retail Preview Session Admin', 'v2-3752-retail-admin@example.test');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $admin;
    }

    private function user(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'password123',
            'locale' => 'ar',
            'is_active' => true,
        ]);
    }
}
