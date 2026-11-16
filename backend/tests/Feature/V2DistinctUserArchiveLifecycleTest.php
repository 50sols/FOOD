<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\RbacManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class V2DistinctUserArchiveLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_explicit_archive_revokes_access_preserves_history_and_restores_separately(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $target = $this->withRole('B2B_ADMIN');
        $token = $target->createToken('before-archive')->accessToken;
        $roleId = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail()->id;

        $this->actingAs($admin)->patch(route('admin.security.users.archive', $target), $this->archivePayload($target))
            ->assertRedirect()
            ->assertSessionHas('status', __('admin.security.user_archived'));

        $archived = $target->fresh();
        $this->assertFalse((bool) $archived->is_active);
        $this->assertNotNull($archived->archived_at);
        $this->assertSame($admin->id, (int) $archived->archived_by);
        $this->assertNotNull($archived->deactivated_at);
        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $roleId]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.archived', 'user_id' => $admin->id]);

        $this->actingAs($admin)->patch(route('admin.security.users.status', $target), [
            'is_active' => true,
            'reason' => 'Restore authorized archived employee',
            'impact_reviewed' => '1',
            'confirm_email' => $target->email,
        ])->assertRedirect()->assertSessionHas('status', __('admin.security.user_archive_restored'));

        $restored = $target->fresh();
        $this->assertTrue((bool) $restored->is_active);
        $this->assertNull($restored->archived_at);
        $this->assertNull($restored->archived_by);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.status_changed', 'user_id' => $admin->id]);
        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $roleId]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }

    public function test_deactivation_and_explicit_archive_are_distinct_lossless_states(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $target = $this->withRole('B2B_ADMIN');

        $this->actingAs($admin)->patch(route('admin.security.users.status', $target), [
            'is_active' => false,
            'reason' => 'Temporary suspension for access review',
            'impact_reviewed' => '1',
            'confirm_email' => $target->email,
        ])->assertRedirect();

        $inactive = $target->fresh();
        $this->assertFalse((bool) $inactive->is_active);
        $this->assertNull($inactive->archived_at);
        $this->assertNotNull($inactive->deactivated_at);
        $originalDeactivation = $inactive->deactivated_at->toIso8601String();

        $this->actingAs($admin)->patch(route('admin.security.users.archive', $target), $this->archivePayload($target))
            ->assertRedirect();

        $archived = $target->fresh();
        $this->assertSame($originalDeactivation, $archived->deactivated_at->toIso8601String());
        $this->assertNotNull($archived->archived_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.archived', 'user_id' => $admin->id]);
    }

    public function test_archive_denies_store_operator_direct_http_and_self_offboarding(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $operator = $this->withRole('B2B_ADMIN');
        $target = $this->withRole('B2B_ADMIN');

        $this->actingAs($operator)->patch(route('admin.security.users.archive', $target), $this->archivePayload($target))
            ->assertForbidden();

        $this->actingAs($admin)->patch(route('admin.security.users.archive', $admin), $this->archivePayload($admin))
            ->assertForbidden();

        $this->assertTrue((bool) $target->fresh()->is_active);
        $this->assertNull($target->fresh()->archived_at);
        $this->assertNull($admin->fresh()->archived_at);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.archived']);
    }

    public function test_open_van_visit_blocks_archive_and_preserves_session_and_visit(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $target = $this->withRole('VAN_OPERATOR');
        $token = $target->createToken('in-progress-van-visit')->accessToken;

        VanVisit::query()->create([
            'actor_user_id' => $target->id,
            'customer_type' => 'b2b',
            'customer_id' => 991001,
            'status' => 'started',
            'started_at' => now(),
        ]);

        $this->actingAs($admin)->patch(route('admin.security.users.archive', $target), $this->archivePayload($target))
            ->assertRedirect()
            ->assertSessionHasErrors('user');

        $this->assertTrue((bool) $target->fresh()->is_active);
        $this->assertNull($target->fresh()->archived_at);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
        $this->assertDatabaseHas('van_visits', ['actor_user_id' => $target->id, 'status' => 'started']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.archived']);
    }

    public function test_mismatched_confirmation_and_replay_do_not_create_extra_archive_event(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $target = $this->withRole('B2B_ADMIN');
        $invalid = $this->archivePayload($target);
        $invalid['confirm_archive'] = 'DELETE';

        $this->actingAs($admin)->patch(route('admin.security.users.archive', $target), $invalid)
            ->assertRedirect()
            ->assertSessionHasErrors('confirm_archive');
        $this->assertNull($target->fresh()->archived_at);

        $this->actingAs($admin)->patch(route('admin.security.users.archive', $target), $this->archivePayload($target))
            ->assertRedirect();

        $this->actingAs($admin)->patch(route('admin.security.users.archive', $target), $this->archivePayload($target))
            ->assertRedirect()
            ->assertSessionHasErrors('user');

        $this->assertSame(1, DB::table('audit_logs')->where('event', 'user.archived')->count());
    }

    public function test_stale_activation_cannot_bypass_archive_confirmation_after_concurrent_offboarding(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $target = $this->withRole('B2B_ADMIN');
        $staleBeforeArchival = $target->fresh();

        $this->actingAs($admin)->patch(route('admin.security.users.archive', $target), $this->archivePayload($target))
            ->assertRedirect();

        // Simulate an old route-model snapshot loaded before the archive.
        // The service must lock/reload the real row, requiring a fresh typed
        // restoration confirmation rather than silently re-enabling access.
        $unconfirmed = Request::create('/admin/security/users/'.$target->id.'/status', 'PATCH', [
            'is_active' => true,
        ]);
        try {
            app(RbacManager::class)->setUserActive(
                $admin,
                $staleBeforeArchival,
                true,
                null,
                $unconfirmed,
            );
            $this->fail('A stale activation snapshot must not restore an archived account.');
        } catch (ValidationException) {
            // Required reason/email/impact confirmation denied before write.
        }

        $this->assertFalse((bool) $target->fresh()->is_active);
        $this->assertNotNull($target->fresh()->archived_at);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'user.archived')->count());
    }

    public function test_existing_security_users_menu_and_archived_badge_are_bilingual(): void
    {
        $admin = $this->withRole('SUPER_ADMIN');
        $target = $this->withRole('B2B_ADMIN');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            $this->actingAs($admin->fresh())
                ->get(route('admin.security.index', ['section' => 'users']))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-v2-open-user-dialog="archive"', false)
                ->assertSee(__('admin.security.archive', [], $locale))
                ->assertSee(route('admin.security.users.archive', $target));
        }

        $this->actingAs($admin)->patch(route('admin.security.users.archive', $target), $this->archivePayload($target))
            ->assertRedirect();

        foreach (['ar', 'en'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();
            $this->actingAs($admin->fresh())
                ->get(route('admin.security.index', ['section' => 'users', 'status' => 'inactive']))
                ->assertOk()
                ->assertSee('data-v2-user-lifecycle-state="archived"', false)
                ->assertSee(__('admin.security.archived_badge', [], $locale))
                ->assertSee(__('admin.security.restore_archive', [], $locale));
        }
    }

    private function withRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }

    /** @return array<string, string> */
    private function archivePayload(User $target): array
    {
        return [
            'reason' => 'Reviewed and authorized permanent archival of this identity',
            'impact_reviewed' => '1',
            'confirm_email' => $target->email,
            'confirm_archive' => 'ARCHIVE',
        ];
    }
}
