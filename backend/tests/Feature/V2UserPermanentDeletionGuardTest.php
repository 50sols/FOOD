<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AdminUserDeletionService;
use App\Services\AuditLogger;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2UserPermanentDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_only_unlinked_inactive_identity_can_be_permanently_deleted_with_transactional_audit(): void
    {
        $admin = $this->makeUser('SUPER_ADMIN');
        $target = User::factory()->create(['is_active' => false, 'locale' => 'en']);
        $targetId = $target->getKey();

        $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'users', 'deletion_preview' => $targetId]))
            ->assertOk()
            ->assertSee('data-v2-user-deletion-eligible', false)
            ->assertSee(__('v2_user_deletion.allowed', [], 'en'))
            ->assertSee(route('admin.security.users.destroy', $target));

        $this->actingAs($admin)
            ->delete(route('admin.security.users.destroy', $target), [
                'reason' => 'Created for a rejected registration with no retained activity.',
                'confirm_email' => $target->email,
                'confirm_delete' => 'DELETE',
                'impact_reviewed' => '1',
            ])
            ->assertRedirect(route('admin.security.index', ['section' => 'users', 'status' => 'inactive']));

        $this->assertDatabaseMissing('users', ['id' => $targetId]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'event' => 'user.permanently_deleted',
        ]);
    }

    public function test_retained_role_and_audit_relationships_block_deletion_without_cascading_or_revocation(): void
    {
        $admin = $this->makeUser('SUPER_ADMIN');
        $target = $this->makeUser('B2B_ADMIN', false);
        app(AuditLogger::class)->record('user.retention_probe', $admin, $target);

        $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'users', 'deletion_preview' => $target->id]))
            ->assertOk()
            ->assertSee('data-v2-user-deletion-blocked', false)
            ->assertSee(__('v2_user_deletion.references', [], 'en'))
            ->assertDontSee('data-v2-user-deletion-form', false);

        $this->actingAs($admin)
            ->delete(route('admin.security.users.destroy', $target), [
                'reason' => 'Attempt to purge an account linked to retained activity',
                'confirm_email' => $target->email,
                'confirm_delete' => 'DELETE',
                'impact_reviewed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => 0]);
        $this->assertDatabaseHas('role_user', ['user_id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.retention_probe']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.permanently_deleted']);
    }

    public function test_archival_actor_reference_blocks_deletion_without_erasing_audited_provenance(): void
    {
        $admin = $this->makeUser('SUPER_ADMIN');
        // This account has no roles or independent finance/audit references.
        // A prior archive performed before role removal retains its identity.
        $formerArchiver = User::factory()->create(['is_active' => false, 'locale' => 'en']);
        $archivedUser = User::factory()->create([
            'is_active' => false,
            'archived_at' => now(),
            'archived_by' => $formerArchiver->id,
        ]);

        $inspection = app(AdminUserDeletionService::class)->inspect($admin, $formerArchiver);
        $this->assertFalse($inspection['eligible']);
        $this->assertContains('references', $inspection['reasons']);

        $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'users', 'deletion_preview' => $formerArchiver->id]))
            ->assertOk()
            ->assertSee('data-v2-user-deletion-blocked', false)
            ->assertSee(__('v2_user_deletion.references', [], 'en'));

        $this->actingAs($admin)
            ->delete(route('admin.security.users.destroy', $formerArchiver), [
                'reason' => 'Attempt to delete a retained archival actor identity',
                'confirm_email' => $formerArchiver->email,
                'confirm_delete' => 'DELETE',
                'impact_reviewed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', ['id' => $formerArchiver->id]);
        $this->assertDatabaseHas('users', [
            'id' => $archivedUser->id,
            'archived_by' => $formerArchiver->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.permanently_deleted']);
    }

    public function test_active_and_privileged_accounts_can_never_be_deleted_even_with_direct_post(): void
    {
        $admin = $this->makeUser('SUPER_ADMIN');
        $active = User::factory()->create(['is_active' => true]);
        $privileged = $this->makeUser('SUPER_ADMIN', false);

        foreach ([$active, $privileged, $admin] as $target) {
            $this->actingAs($admin)
                ->delete(route('admin.security.users.destroy', $target), [
                    'reason' => 'Attempting a protected-account deletion',
                    'confirm_email' => $target->email,
                    'confirm_delete' => 'DELETE',
                    'impact_reviewed' => '1',
                ])
                ->assertRedirect()
                ->assertSessionHasErrors('user');

            $this->assertDatabaseHas('users', ['id' => $target->id]);
        }

        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.permanently_deleted']);
    }

    public function test_existing_mobile_sanctum_token_is_retained_and_blocks_delete(): void
    {
        $admin = $this->makeUser('SUPER_ADMIN');
        $target = User::factory()->create(['is_active' => false]);
        $token = $target->createToken('existing-mobile-session');

        $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'users', 'deletion_preview' => $target->id]))
            ->assertOk()
            ->assertSee('data-v2-user-deletion-blocked', false)
            ->assertDontSee('data-v2-user-deletion-form', false);

        $this->actingAs($admin)
            ->delete(route('admin.security.users.destroy', $target), [
                'reason' => 'Attempt deletion with a retained mobile session',
                'confirm_email' => $target->email,
                'confirm_delete' => 'DELETE',
                'impact_reviewed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->getKey(),
            'tokenable_id' => $target->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_consumer_identity_is_routed_to_the_separate_privacy_deletion_workflow(): void
    {
        $admin = $this->makeUser('SUPER_ADMIN');
        $customerIdentity = User::factory()->create([
            'is_active' => false,
            'is_platform_customer' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'users', 'deletion_preview' => $customerIdentity->id]))
            ->assertOk()
            ->assertSee(__('v2_user_deletion.consumer'))
            ->assertDontSee('data-v2-user-deletion-form', false);

        $this->actingAs($admin)
            ->delete(route('admin.security.users.destroy', $customerIdentity), [
                'reason' => 'Attempting to bypass customer privacy review',
                'confirm_email' => $customerIdentity->email,
                'confirm_delete' => 'DELETE',
                'impact_reviewed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', ['id' => $customerIdentity->id]);
    }

    public function test_historical_audit_target_alone_blocks_permanent_deletion_even_without_role_links(): void
    {
        $admin = $this->makeUser('SUPER_ADMIN');
        $target = User::factory()->create(['is_active' => false]);
        app(AuditLogger::class)->record('user.privacy_history_retained', $admin, $target);

        $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'users', 'deletion_preview' => $target->id]))
            ->assertOk()
            ->assertSee('data-v2-user-deletion-blocked', false);

        $this->actingAs($admin)
            ->delete(route('admin.security.users.destroy', $target), [
                'reason' => 'Try deletion of a historically audited identity',
                'confirm_email' => $target->email,
                'confirm_delete' => 'DELETE',
                'impact_reviewed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.privacy_history_retained']);
    }

    public function test_direct_forbidden_requests_cannot_preview_or_delete_user_from_unprivileged_account(): void
    {
        $operator = $this->makeUser('B2B_ADMIN');
        $target = User::factory()->create(['is_active' => false]);

        $this->actingAs($operator)
            ->get(route('admin.security.index', ['section' => 'users', 'deletion_preview' => $target->id]))
            ->assertForbidden();

        $this->actingAs($operator)
            ->delete(route('admin.security.users.destroy', $target), [
                'reason' => 'Unauthorized attempted permanent deletion',
                'confirm_email' => $target->email,
                'confirm_delete' => 'DELETE',
                'impact_reviewed' => '1',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.permanently_deleted']);
    }

    public function test_confirmation_mismatch_never_deletes_user_or_creates_success_audit(): void
    {
        $admin = $this->makeUser('SUPER_ADMIN');
        $target = User::factory()->create(['is_active' => false]);

        $this->actingAs($admin)
            ->delete(route('admin.security.users.destroy', $target), [
                'reason' => 'Attempt with invalid typed identity confirmation',
                'confirm_email' => 'wrong@example.test',
                'confirm_delete' => 'DELETE',
                'impact_reviewed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('confirm_email');

        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.permanently_deleted']);
    }

    public function test_bilingual_existing_users_view_previews_links_in_english_and_arabic(): void
    {
        $admin = $this->makeUser('SUPER_ADMIN');
        $target = $this->makeUser('B2B_ADMIN', false);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin->fresh())
                ->get(route('admin.security.index', ['section' => 'users', 'status' => 'inactive']))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-v2-user-deletion-preview', false)
                ->assertSee(__('v2_user_deletion.preview', [], $locale));

            $this->actingAs($admin->fresh())
                ->get(route('admin.security.index', ['section' => 'users', 'deletion_preview' => $target->id]))
                ->assertOk()
                ->assertSee(__('v2_user_deletion.blocked', [], $locale));
        }
    }

    private function makeUser(string $role, bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => $active, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
