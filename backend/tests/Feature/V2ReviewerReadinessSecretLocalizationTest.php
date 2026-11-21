<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StoreReviewerAccount;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2ReviewerReadinessSecretLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_missing_reviewer_secret_is_localized_for_authorized_admin_and_never_writes(): void
    {
        $admin = User::query()->create([
            'name' => 'Reviewer readiness admin',
            'email' => 'readiness-admin@example.test',
            'password' => 'unused-password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $reviewer = StoreReviewerAccount::query()->create([
            'app' => 'customer',
            'platform' => 'android',
            'environment' => 'staging',
            'persona' => 'missing-secret-reviewer',
            'identifier_type' => 'email',
            'identifier' => 'missing-secret@example.test',
            'secret_encrypted' => null,
            'readiness_status' => 'BLOCKED',
            'readiness_message' => 'Awaiting credentials',
            'last_tested_at' => null,
        ]);

        $initialUpdatedAt = $reviewer->updated_at?->toISOString();
        $initialAuditCount = DB::table('audit_logs')->count();
        $url = '/admin/settings/mobile/reviewer/'.$reviewer->id.'/test';

        foreach ([
            'ar' => 'لم يتم إعداد كلمة مرور حساب المراجع.',
            'en' => 'Reviewer secret is not configured.',
        ] as $locale => $message) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin)
                ->postJson($url)
                ->assertUnprocessable()
                ->assertJsonPath('errors.reviewer.0', $message);

            $current = $reviewer->fresh();
            $this->assertSame('BLOCKED', $current->readiness_status);
            $this->assertSame('Awaiting credentials', $current->readiness_message);
            $this->assertNull($current->last_tested_at);
            $this->assertNull($current->getRawOriginal('secret_encrypted'));
            $this->assertSame($initialUpdatedAt, $current->updated_at?->toISOString());
            $this->assertSame($initialAuditCount, DB::table('audit_logs')->count());
        }

        $unprivileged = User::query()->create([
            'name' => 'Unprivileged reviewer operator',
            'email' => 'unprivileged-readiness@example.test',
            'password' => 'unused-password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $this->actingAs($unprivileged)->postJson($url)->assertForbidden();

        $this->assertSame($initialUpdatedAt, $reviewer->fresh()->updated_at?->toISOString());
        $this->assertSame($initialAuditCount, DB::table('audit_logs')->count());
    }
}
