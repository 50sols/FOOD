<?php

namespace Tests\Feature;

use App\Models\MobileStoreSubmission;
use App\Models\Role;
use App\Models\StoreReviewerAccount;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2StoreSubmissionSuccessLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_submission_reviewer_save_and_secret_rotation_flash_follow_the_operator_locale(): void
    {
        foreach ([
            'ar' => [
                'submission' => 'تم حفظ بيانات نشر التطبيق.',
                'reviewer' => 'تم حفظ إعدادات حساب المراجع.',
                'rotated' => 'تم تغيير كلمة مرور المراجع.',
            ],
            'en' => [
                'submission' => 'Store submission metadata saved.',
                'reviewer' => 'Reviewer account configuration saved.',
                'rotated' => 'Reviewer secret rotated.',
            ],
        ] as $locale => $messages) {
            $admin = User::query()->create([
                'name' => 'Submission admin '.$locale,
                'email' => 'submission-success-'.$locale.'@example.test',
                'password' => 'unused-password',
                'locale' => $locale,
                'is_active' => true,
            ]);
            $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

            $submission = $this->submissionData();
            $this->actingAs($admin)
                ->put('/admin/settings/mobile/submission', $submission)
                ->assertRedirect()
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', $messages['submission']);

            $this->assertDatabaseHas('mobile_store_submissions', [
                'app' => 'customer',
                'platform' => 'android',
                'environment' => 'staging',
                'submission_status' => 'NOT_READY',
            ]);

            $this->actingAs($admin)
                ->put('/admin/settings/mobile/reviewer', $this->reviewerData($locale))
                ->assertRedirect()
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', $messages['reviewer']);

            $reviewer = StoreReviewerAccount::query()
                ->where('persona', 'localized_'.$locale)
                ->firstOrFail();
            $this->assertSame('initial-secret-'.$locale, $reviewer->secret_encrypted);
            $this->assertNotSame('initial-secret-'.$locale, $reviewer->getRawOriginal('secret_encrypted'));

            $this->actingAs($admin)
                ->post('/admin/settings/mobile/reviewer/'.$reviewer->id.'/rotate', [
                    'reviewer_secret' => 'rotated-secret-'.$locale,
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', $messages['rotated']);

            $fresh = $reviewer->fresh();
            $this->assertSame('rotated-secret-'.$locale, $fresh->secret_encrypted);
            $this->assertNotSame('rotated-secret-'.$locale, $fresh->getRawOriginal('secret_encrypted'));
            $this->assertSame('BLOCKED', $fresh->readiness_status);
            $this->assertNull($fresh->last_tested_at);

            $this->assertSame(
                2,
                DB::table('audit_logs')
                    ->whereIn('event', ['store_reviewer_account.updated', 'store_reviewer_account.secret_rotated'])
                    ->where('user_id', $admin->id)
                    ->count(),
            );
        }
    }

    public function test_roleless_actor_cannot_save_submission_or_reviewer_or_rotate_secret(): void
    {
        $admin = User::query()->create([
            'name' => 'Authorized operator',
            'email' => 'submission-authorized@example.test',
            'password' => 'unused-password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->actingAs($admin)
            ->put('/admin/settings/mobile/reviewer', $this->reviewerData('existing'))
            ->assertRedirect();
        $reviewer = StoreReviewerAccount::query()->where('persona', 'localized_existing')->firstOrFail();
        $storedSecret = $reviewer->getRawOriginal('secret_encrypted');
        $beforeAudit = DB::table('audit_logs')->count();
        $beforeSubmission = MobileStoreSubmission::query()->count();
        $beforeReviewer = StoreReviewerAccount::query()->count();

        $roleless = User::query()->create([
            'name' => 'Unprivileged operator',
            'email' => 'submission-roleless@example.test',
            'password' => 'unused-password',
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $this->actingAs($roleless)
            ->put('/admin/settings/mobile/submission', $this->submissionData())
            ->assertForbidden();
        $this->actingAs($roleless)
            ->put('/admin/settings/mobile/reviewer', $this->reviewerData('unauthorized'))
            ->assertForbidden();
        $this->actingAs($roleless)
            ->post('/admin/settings/mobile/reviewer/'.$reviewer->id.'/rotate', [
                'reviewer_secret' => 'forbidden-rotation',
            ])
            ->assertForbidden();

        $this->assertSame($beforeSubmission, MobileStoreSubmission::query()->count());
        $this->assertSame($beforeReviewer, StoreReviewerAccount::query()->count());
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
        $this->assertSame($storedSecret, $reviewer->fresh()->getRawOriginal('secret_encrypted'));
    }

    /** @return array<string, string> */
    private function submissionData(): array
    {
        return [
            'app' => 'customer',
            'platform' => 'android',
            'environment' => 'staging',
            'update_policy' => 'optional',
            'submission_status' => 'NOT_READY',
            'signing_readiness' => 'BLOCKED',
            'firebase_readiness' => 'BLOCKED',
            'apns_readiness' => 'BLOCKED',
            'deep_link_readiness' => 'BLOCKED',
            'production_environment_readiness' => 'BLOCKED',
            'readiness_state' => 'BLOCKED',
        ];
    }

    /** @return array<string, string> */
    private function reviewerData(string $suffix): array
    {
        return [
            'app' => 'customer',
            'platform' => 'android',
            'environment' => 'staging',
            'persona' => 'localized_'.$suffix,
            'identifier_type' => 'email',
            'identifier' => 'app-reviewer-'.$suffix.'@example.test',
            'reviewer_secret' => 'initial-secret-'.$suffix,
            'is_active' => '1',
        ];
    }
}
