<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemVersion;
use App\Models\UpdateHistory;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class SystemUpdateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_can_open_system_update_center(): void
    {
        $user = $this->userWithRole('SUPER_ADMIN');

        $this->actingAs($user)
            ->get('/admin/settings/system-update')
            ->assertOk()
            ->assertSee('System Update')
            ->assertSee('Runtime health & recovery')
            ->assertSee('Backup Center')
            ->assertSee('Recent failed jobs')
            ->assertSee('Validated update package');

        $this->actingAs($user)
            ->get('/admin/b2b/dashboard')
            ->assertOk()
            ->assertSee('href="'.route('admin.administration.index').'"', false)
            ->assertDontSee('href="'.route('admin.system-update.index').'"', false);
    }

    public function test_system_update_center_repairs_legacy_compact_installed_version(): void
    {
        $user = $this->userWithRole('SUPER_ADMIN');

        SystemVersion::query()->create([
            'version' => '1.084',
            'installed_at' => now(),
            'package_hash' => str_repeat('a', 64),
        ]);

        UpdateHistory::query()->create([
            'from_version' => '1.083',
            'to_version' => '1.084',
            'status' => 'success',
            'package_hash' => str_repeat('a', 64),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        UpdateHistory::query()->create([
            'from_version' => '1.084',
            'to_version' => '1.0.85',
            'status' => 'failed',
            'package_hash' => str_repeat('b', 64),
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/admin/settings/system-update')
            ->assertOk()
            ->assertSee('1.0.84');

        $this->assertDatabaseMissing('system_versions', ['version' => '1.084']);
        $this->assertDatabaseHas('system_versions', ['version' => '1.0.84']);
        $this->assertDatabaseHas('update_history', [
            'to_version' => '1.0.84',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('update_history', [
            'from_version' => '1.0.84',
            'to_version' => '1.0.85',
            'status' => 'failed',
        ]);
    }

    public function test_non_update_admin_cannot_open_system_update_center(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $user = $this->userWithRole('B2B_ADMIN');

        $this->actingAs($user)
            ->get('/admin/settings/system-update')
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.system-update.backups.store'), ['reason' => 'unauthorized backup'])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.system-update.backups.restore', [
                'backupReference' => '20261017-120000-database-aaaaaaaaaa',
            ]), [
                'reason' => 'unauthorized restore',
                'confirm_reference' => '20261017-120000-database-aaaaaaaaaa',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('href="'.route('admin.system-update.index').'"', false);
    }

    public function test_system_administrator_with_system_update_permission_cannot_access_high_risk_update_or_recovery_routes(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $administrator = $this->userWithRole('SYSTEM_ADMINISTRATOR');
        $this->assertTrue($administrator->hasPermission('system.update'));
        $this->assertFalse($administrator->hasRole('SUPER_ADMIN'));

        $this->actingAs($administrator)
            ->get(route('admin.system-update.index'))
            ->assertForbidden();

        $this->actingAs($administrator)
            ->post(route('admin.system-update.store'), [])
            ->assertForbidden();

        $this->actingAs($administrator)
            ->post(route('admin.system-update.backups.store'), ['reason' => 'forbidden backup'])
            ->assertForbidden();

        $this->actingAs($administrator)
            ->post(route('admin.system-update.backups.restore', [
                'backupReference' => '20261017-120000-database-aaaaaaaaaa',
            ]), [
                'reason' => 'forbidden restore',
                'confirm_reference' => '20261017-120000-database-aaaaaaaaaa',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('update_history', 0);
    }

    public function test_super_admin_reaches_validation_for_update_backup_and_restore_actions(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $superAdmin = $this->userWithRole('SUPER_ADMIN');

        $this->actingAs($superAdmin)
            ->post(route('admin.system-update.store'), [])
            ->assertSessionHasErrors(['package', 'target_version', 'minimum_current_version', 'sha256']);

        $this->actingAs($superAdmin)
            ->post(route('admin.system-update.backups.store'), [])
            ->assertSessionHasErrors(['reason']);

        $this->actingAs($superAdmin)
            ->post(route('admin.system-update.backups.restore', [
                'backupReference' => '20261017-120000-database-aaaaaaaaaa',
            ]), [])
            ->assertSessionHasErrors(['reason', 'confirm_reference']);
    }

    public function test_update_center_shows_bilingual_nonmutating_cpanel_permission_remediation(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $directory = storage_path('framework/testing/update-host-preflight-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0750, true);
        $blockingFile = $directory.'/not-a-directory';
        file_put_contents($blockingFile, 'preserve-host-sentinel');

        config([
            'foodex.update_backup_path' => $blockingFile.'/backups',
            'foodex.updater_execution_lock' => $blockingFile.'/locks/execution.lock',
        ]);

        try {
            foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
                $admin->forceFill(['locale' => $locale])->save();
                app()->setLocale($locale);
                $response = $this->actingAs($admin->fresh())
                    ->get('/admin/settings/system-update')
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee(__('admin.system_update_host.title', [], $locale))
                    ->assertSee(__('admin.system_update_host.action_required', [], $locale))
                    ->assertSee(__('admin.system_update_host.signature_not_certified', [], $locale))
                    ->assertSee(__('admin.system_update_host.manual_verification', [], $locale))
                    ->assertSee(__('update_host_readiness.checks.sodium_signature.label', [], $locale))
                    ->assertSee(__('update_host_readiness.checks.sodium_signature.help', [], $locale))
                    ->assertSee(__('update_host_readiness.checks.upload_temporary.label', [], $locale))
                    ->assertSee(__('update_host_readiness.checks.upload_temporary.help', [], $locale))
                    ->assertSee('data-host-requirement="upload_temporary"', false)
                    ->assertSee(
                        'data-host-requirement="sodium_signature" data-host-status="'
                        .(extension_loaded('sodium')
                            && function_exists('sodium_crypto_sign_verify_detached')
                            && defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')
                            ? 'precheck-ok' : 'host-action-required').'"',
                        false,
                    )
                    ->assertSee('data-host-requirement="backup_directory" data-host-status="host-action-required"', false)
                    ->assertSee('data-host-requirement="update_lock" data-host-status="host-action-required"', false);

                $this->assertStringNotContainsString($directory, (string) $response->getContent());
            }

            // A GET readiness diagnostic must not create private update/lock
            // directories, mutate the sentinel, or claim verified host setup.
            $this->assertSame('preserve-host-sentinel', file_get_contents($blockingFile));
            $this->assertFileDoesNotExist($blockingFile.'/backups');
            $this->assertFileDoesNotExist($blockingFile.'/locks/execution.lock');
        } finally {
            app()->setLocale('en');
            @unlink($blockingFile);
            @rmdir($directory);
        }
    }

    public function test_update_center_preflight_does_not_create_creatable_host_directories(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $directory = storage_path('framework/testing/update-host-uncreated-'.bin2hex(random_bytes(6)));
        config([
            'foodex.update_backup_path' => $directory.'/backups',
            'foodex.updater_execution_lock' => $directory.'/locks/execution.lock',
        ]);

        $this->actingAs($admin)
            ->get('/admin/settings/system-update')
            ->assertOk()
            ->assertSee('data-host-requirement="backup_directory" data-host-status="precheck-ok"', false)
            ->assertSee('data-host-requirement="update_lock" data-host-status="precheck-ok"', false)
            ->assertSee(__('admin.system_update_host.precheck_ok'));

        $this->assertDirectoryDoesNotExist($directory);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-update@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
