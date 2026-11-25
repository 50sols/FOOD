<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SystemUpdateController;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class V2SystemUpdatePersistedActorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_disabled_cached_super_admin_cannot_read_update_backup_or_restore_in_ar_and_en(): void
    {
        // Isolate the controller authorization boundary: an upstream
        // middleware guard must not be the only safeguard for high-risk ZIP
        // upload, database backup and recovery operations.
        $this->withoutMiddleware();
        $actor = $this->superAdmin();
        $this->actingAs($actor);
        $reference = '20261017-120000-database-aaaaaaaaaa';
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
            $this->assertTrue((bool) $actor->is_active, 'The request actor must remain stale.');
            $this->assertFalse((bool) $actor->fresh()->is_active);

            $this->get(route('admin.system-update.index'))->assertForbidden();
            $this->post(route('admin.system-update.store'), [])->assertForbidden();
            $this->post(route('admin.system-update.backups.store'), [
                'reason' => 'Denied backup after deactivation',
            ])->assertForbidden();
            $this->post(route('admin.system-update.backups.restore', [
                'backupReference' => $reference,
            ]), [
                'reason' => 'Denied recovery after deactivation',
                'confirm_reference' => $reference,
            ])->assertForbidden();

            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertDatabaseCount('update_history', 0);
            DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        }
    }

    public function test_update_center_refuses_history_and_recovery_metadata_after_midquery_admin_offboarding_in_ar_and_en(): void
    {
        // The SQL listener supplies deterministic same-connection interleaving,
        // not independent two-session MySQL concurrency certification.
        $this->withoutMiddleware();
        // Bypass security middleware to test the controller guard itself, but
        // restore ONLY the session normally attached by StartSession. Without
        // this fixture, the authorised GET fails with an unrelated 500 before
        // the history SELECT can trigger the persisted revocation.
        $this->app['events']->listen(RouteMatched::class, static function (RouteMatched $matched): void {
            $session = app('session')->driver();
            $session->start();
            $matched->request->setLaravelSession($session);
        });
        $actor = $this->superAdmin();
        $this->actingAs($actor);
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $revokedDuringRead = false;
            DB::listen(function ($event) use ($actor, &$revokedDuringRead): void {
                $sql = strtolower(ltrim($event->sql));
                if ($revokedDuringRead || ! str_starts_with($sql, 'select')
                    || ! str_contains($sql, 'update_history')) {
                    return;
                }

                $revokedDuringRead = true;
                DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
            });

            $this->get(route('admin.system-update.index'))->assertForbidden();
            $this->assertTrue($revokedDuringRead, 'A real update history read must trigger revocation.');
            $this->assertTrue((bool) $actor->is_active, 'The request principal stays cached.');
            $this->assertFalse((bool) $actor->fresh()->is_active);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertDatabaseCount('update_history', 0);
            DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        }
    }

    public function test_updater_authorization_rebinds_original_identity_and_rejects_dirty_primary_key(): void
    {
        $actor = $this->superAdmin();
        $request = Request::create('/admin/settings/system-update', 'GET');
        $request->setUserResolver(static fn (): User => $actor);
        $guard = new ReflectionMethod(SystemUpdateController::class, 'authorizeSuperAdminUpdate');
        $controller = app(SystemUpdateController::class);

        $current = $guard->invoke($controller, $request);
        $this->assertInstanceOf(User::class, $current);
        $this->assertSame($actor->getKey(), $current->getKey());
        $this->assertNotSame($actor, $current);

        $other = User::query()->create([
            'name' => 'Unrelated persisted identity',
            'email' => 'unrelated-update-actor@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->setAttribute($actor->getKeyName(), $other->getKey());
        $this->assertNotSame(
            (string) $actor->getRawOriginal($actor->getKeyName()),
            (string) $actor->getKey(),
        );

        try {
            $guard->invoke($controller, $request);
            $this->fail('A mutated cached user key must not select another update principal.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('update_history', 0);
    }

    private function superAdmin(): User
    {
        $actor = User::query()->create([
            'name' => 'V2 Updater Source-Principal Owner',
            'email' => 'v2-updater-persisted-actor@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }
}
