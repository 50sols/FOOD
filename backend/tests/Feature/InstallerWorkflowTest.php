<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerChecklist;
use App\Domain\Installer\InstallerEnvironment;
use App\Domain\Installer\InstallerWorkflow;
use App\Domain\Installer\InstallState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class InstallerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $testDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDirectory = storage_path('framework/testing/installer-'.bin2hex(random_bytes(6)));
        mkdir($this->testDirectory, 0750, true);

        $environmentPath = $this->testDirectory.'/.env';
        file_put_contents($environmentPath, implode(PHP_EOL, [
            'APP_NAME=FOODEX',
            'APP_ENV=testing',
            'APP_KEY=',
            'APP_DEBUG=true',
            'APP_URL=http://localhost',
            'APP_LOCALE=ar',
            'DB_CONNECTION=sqlite',
            'DB_DATABASE=:memory:',
            'FILESYSTEM_DISK=local',
            'CACHE_STORE=array',
            'QUEUE_CONNECTION=sync',
            'MAIL_MAILER=array',
            'MAIL_FROM_ADDRESS=noreply@example.com',
            'MAIL_FROM_NAME=FOODEX',
        ]).PHP_EOL);

        config([
            'foodex.installer_env_path' => $environmentPath,
            'foodex.install_lock' => $this->testDirectory.'/installed.lock',
            'foodex.install_progress' => $this->testDirectory.'/install-progress.json',
            'foodex.scheduler_auto_provision' => false,
            'foodex.scheduler_heartbeat' => false,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->testDirectory) && is_dir($this->testDirectory)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->testDirectory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }

            rmdir($this->testDirectory);
        }

        parent::tearDown();
    }

    public function test_storage_configuration_rejects_unmapped_public_directory_without_changing_environment(): void
    {
        $originalPublicPath = app()->publicPath();
        $originalStoragePath = app()->storagePath();
        $publicPath = $this->testDirectory.'/cpanel-public';
        $storagePath = $this->testDirectory.'/cpanel-storage';

        mkdir($publicPath.'/storage', 0750, true);
        mkdir($storagePath, 0750, true);

        app()->usePublicPath($publicPath);
        app()->useStoragePath($storagePath);

        try {
            $environment = app(InstallerEnvironment::class);
            $before = file_get_contents($environment->path());

            try {
                app(InstallerWorkflow::class)->saveStorageConfiguration('public');
                $this->fail('A real public/storage directory does not map storage/app/public.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(__('installer.errors.public_storage_link'), $exception->getMessage());
            }

            $this->assertSame($before, file_get_contents($environment->path()));
            $this->assertDirectoryExists($publicPath.'/storage');
            $this->assertFalse(is_link($publicPath.'/storage'));
        } finally {
            app()->usePublicPath($originalPublicPath);
            app()->useStoragePath($originalStoragePath);
        }
    }

    public function test_installer_exposes_exactly_fifteen_ordered_steps(): void
    {
        $this->assertCount(15, InstallerChecklist::steps());
        $this->assertSame('Welcome', InstallerChecklist::step(1)['title']);
        $this->assertSame('Finish', InstallerChecklist::step(15)['title']);

        $this->get('/install')
            ->assertOk()
            ->assertSee('FOODEX')
            ->assertSee('مرحبًا')
            ->assertSee('إنهاء التثبيت');
    }

    public function test_database_connection_allows_empty_fresh_install_database(): void
    {
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('system_versions', 0);

        app(InstallerWorkflow::class)->testDatabaseConnection();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_database_connection_rejects_existing_users_without_install_lock(): void
    {
        $this->assertFalse(app(InstallState::class)->isInstalled());
        DB::table('users')->insert([
            'name' => 'Existing Operator',
            'email' => 'existing-operator@example.test',
            'password' => 'existing-hash-not-for-auth',
            'locale' => 'en',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already contains FOODEX data');
        app(InstallerWorkflow::class)->testDatabaseConnection();
    }

    public function test_database_connection_rejects_existing_version_without_install_lock(): void
    {
        $this->assertFalse(app(InstallState::class)->isInstalled());
        DB::table('system_versions')->insert([
            'version' => '99.0.0',
            'installed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already contains FOODEX data');
        app(InstallerWorkflow::class)->testDatabaseConnection();
    }

    public function test_migrations_never_run_against_database_with_existing_user_data(): void
    {
        DB::table('users')->insert([
            'name' => 'Existing Operator',
            'email' => 'existing-before-migrate@example.test',
            'password' => 'existing-hash-not-for-auth',
            'locale' => 'ar',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::shouldReceive('call')->never();

        try {
            app(InstallerWorkflow::class)->runMigrations();
            $this->fail('Migrations must reject an existing FOODEX database.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('already contains FOODEX data', $exception->getMessage());
        }
        $this->assertDatabaseHas('users', [
            'email' => 'existing-before-migrate@example.test',
            'password' => 'existing-hash-not-for-auth',
        ]);
    }

    public function test_failed_migration_records_only_redacted_first_failure_and_never_retries(): void
    {
        Artisan::shouldReceive('call')->once()
            ->with('migrate', ['--force' => true], \Mockery::type(BufferedOutput::class))
            ->andReturnUsing(static function (string $command, array $options, BufferedOutput $output): int {
                $output->writeln('2026_09_24_000100_create_identity_store_foundation FAILED SQLSTATE[42S01] password=secret-should-not-appear');

                return 1;
            });

        Log::shouldReceive('error')->once()
            ->with('Installer migration failed', [
                'sqlstate' => '42S01',
                'migration' => '2026_09_24_000100_create_identity_store_foundation',
            ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage((string) __('installer.errors.migrations'));

        app(InstallerWorkflow::class)->runMigrations();
    }

    public function test_thrown_migration_exception_records_only_redacted_first_failure_and_never_retries(): void
    {
        Artisan::shouldReceive('call')->once()
            ->with('migrate', ['--force' => true], \Mockery::type(BufferedOutput::class))
            ->andThrow(new \RuntimeException(
                'SQLSTATE[42S02]: Base table or view not found: 1146 2026_09_24_000100_create_identity_store_foundation password=secret-should-not-appear'
            ));

        Log::shouldReceive('error')->once()
            ->with('Installer migration failed', [
                'sqlstate' => '42S02',
                'migration' => '2026_09_24_000100_create_identity_store_foundation',
            ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage((string) __('installer.errors.migrations'));

        app(InstallerWorkflow::class)->runMigrations();
    }

    public function test_database_step_refuses_to_replace_unlocked_live_environment_and_preserves_secrets(): void
    {
        $environment = app(InstallerEnvironment::class);
        $environment->write([
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'previous-production-host.invalid',
            'DB_DATABASE' => 'live_foodex',
            'DB_USERNAME' => 'previous_owner',
            'DB_PASSWORD' => 'previous-secret-test-only',
        ]);
        $original = file_get_contents($environment->path());
        $this->assertFalse(app(InstallState::class)->isInstalled());

        $state = app(InstallState::class);
        $state->markStepComplete(3);
        $this->post('/install/step/4', [
            'installer_token' => $state->token(),
            'db_host' => 'attacker.invalid',
            'db_port' => 3306,
            'db_database' => 'new_database',
            'db_username' => 'new_operator',
            'db_password' => 'new-password-test-only',
        ])->assertStatus(422)
            ->assertSee(__('installer.environment_already_configured'), false)
            ->assertDontSee('previous-secret-test-only')
            ->assertDontSee('new-password-test-only');

        $this->assertSame($original, file_get_contents($environment->path()));
        $this->assertSame(3, $state->progress()['completed_step']);
    }

    public function test_database_configuration_refuses_nonempty_app_key_even_when_env_is_not_production(): void
    {
        app()->setLocale('en');
        $environment = app(InstallerEnvironment::class);
        $environment->write([
            'APP_ENV' => 'local',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        ]);
        $original = file_get_contents($environment->path());

        try {
            app(InstallerWorkflow::class)->saveDatabaseConfiguration([
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'new_foodex',
                'username' => 'fresh_user',
                'password' => 'new-password-test-only',
            ]);
            $this->fail('An existing APP_KEY must protect .env before DB configuration writes.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(__('installer.environment_already_configured'), $exception->getMessage());
        }

        $this->assertSame($original, file_get_contents($environment->path()));
    }

    public function test_unlocked_production_env_with_empty_key_rejects_existing_database_data_before_write(): void
    {
        DB::table('users')->insert([
            'name' => 'Existing Live Operator',
            'email' => 'existing-unlocked-user@example.test',
            'password' => 'existing-hash-not-for-auth',
            'locale' => 'ar',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $environment = app(InstallerEnvironment::class);
        $environment->write(['APP_ENV' => 'production', 'APP_KEY' => '']);
        $original = file_get_contents($environment->path());

        try {
            app(InstallerWorkflow::class)->saveDatabaseConfiguration([
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'new_foodex',
                'username' => 'fresh_user',
                'password' => 'new-password-test-only',
            ]);
            $this->fail('An unlocked production .env with existing FOODEX user data must not be replaced.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(__('installer.environment_already_configured'), $exception->getMessage());
        }

        $this->assertSame($original, file_get_contents($environment->path()));
    }

    public function test_clean_production_setup_template_with_empty_key_can_save_database_settings(): void
    {
        $environment = app(InstallerEnvironment::class);
        $environment->write(['APP_ENV' => 'production', 'APP_KEY' => '']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('system_versions', 0);

        app(InstallerWorkflow::class)->saveDatabaseConfiguration([
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'foodex_fresh_setup',
            'username' => 'setup_operator',
            'password' => 'test-only-password',
        ]);

        $stored = $environment->read(['APP_ENV', 'APP_KEY', 'DB_CONNECTION', 'DB_DATABASE', 'DB_USERNAME']);
        $this->assertSame('production', $stored['APP_ENV']);
        $this->assertSame('', $stored['APP_KEY']);
        $this->assertSame('mysql', $stored['DB_CONNECTION']);
        $this->assertSame('foodex_fresh_setup', $stored['DB_DATABASE']);
        $this->assertSame('setup_operator', $stored['DB_USERNAME']);
    }

    public function test_database_configuration_persists_mysql_production_defaults(): void
    {
        $workflow = app(InstallerWorkflow::class);
        $workflow->saveDatabaseConfiguration([
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'solscool_foodex',
            'username' => 'solscool_foodex',
            'password' => 'test-only-password',
        ]);

        $values = app(InstallerEnvironment::class)->read([
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME',
        ]);

        $this->assertSame('mysql', $values['DB_CONNECTION']);
        $this->assertSame('localhost', $values['DB_HOST']);
        $this->assertSame('3306', $values['DB_PORT']);
        $this->assertSame('solscool_foodex', $values['DB_DATABASE']);
        $this->assertSame('solscool_foodex', $values['DB_USERNAME']);
    }

    public function test_installer_post_requires_the_progress_token(): void
    {
        $this->post('/install/step/1')->assertStatus(419);
        $this->assertFalse(app(InstallState::class)->isInstalled());
    }

    public function test_step_validation_blocks_invalid_super_admin_input(): void
    {
        app(InstallState::class)->markStepComplete(6);

        $state = app(InstallState::class);

        $this->post('/install/step/7?lang=en', ['installer_token' => $state->token()])
            ->assertStatus(422)
            ->assertSee('The Name field is required.', false)
            ->assertSee('The Email field is required.', false)
            ->assertSee('The Password field is required.', false);

        $this->assertFalse(app(InstallState::class)->isInstalled());
    }

    public function test_failed_finish_does_not_write_install_lock(): void
    {
        app(InstallState::class)->markStepComplete(14);

        $state = app(InstallState::class);

        $this->post('/install/step/15?lang=en', ['installer_token' => $state->token()])
            ->assertStatus(422)
            ->assertSee('Super Admin setup is incomplete', false);

        $this->assertFalse(app(InstallState::class)->isInstalled());
        $this->assertDatabaseCount('system_versions', 0);
    }

    public function test_successful_finish_creates_super_admin_version_and_lock(): void
    {
        $workflow = app(InstallerWorkflow::class);
        $state = app(InstallState::class);

        $workflow->runMigrations();
        $workflow->seedRequiredCoreData();
        $workflow->generateApplicationKey();
        $this->assertSame([
            'database' => true,
            'storage' => true,
            'cache' => true,
            'queue' => true,
        ], $workflow->healthCheck());

        $admin = $workflow->prepareSuperAdmin([
            'name' => 'Platform Owner',
            'email' => 'owner@example.com',
            'password' => 'SecurePassword!123',
            'locale' => 'ar',
        ]);
        $state->markStepComplete(14, $admin);

        $this->post('/install/step/15', ['installer_token' => $state->token()])
            ->assertRedirect(route('admin.b2c.login'));

        $version = trim((string) file_get_contents(base_path('../VERSION')));
        $this->assertTrue($state->isInstalled());
        $this->assertDatabaseHas('system_versions', ['version' => $version]);
        $this->assertDatabaseHas('users', ['email' => 'owner@example.com', 'is_active' => 1]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'installer.completed']);

        $user = DB::table('users')->where('email', 'owner@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('SecurePassword!123', (string) $user->password));

        $superAdminRoleId = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');
        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $superAdminRoleId,
        ]);

        $this->get('/install')->assertNotFound();
        $this->post('/install/step/1', ['installer_token' => 'invalid'])->assertNotFound();
    }
}
