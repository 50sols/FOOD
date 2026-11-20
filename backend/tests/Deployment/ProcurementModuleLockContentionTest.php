<?php

namespace Tests\Deployment;

use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Runs exclusively against the real disposable MySQL service in Backend CI.
 *
 * The feature suite's QueryExecuted listeners simulate stale snapshots in one
 * connection. This test separately proves that the module guard's FOR UPDATE
 * lock participates in genuine, independent InnoDB session contention. It is
 * not a substitute for a two-process supplier HTTP transaction/load test.
 */
final class ProcurementModuleLockContentionTest extends TestCase
{
    public function test_two_mysql_sessions_serialize_procurement_shutdown_and_supplier_write_lock(): void
    {
        $this->assertSame('true', getenv('FOODEX_DISPOSABLE_SERVICES'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('foodex_acceptance', DB::connection()->getDatabaseName());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));

        $original = DB::table('enterprise_modules')->where('code', 'procurement')->first();
        app(ModuleRegistry::class)->syncManifest('procurement');

        $configuration = config('database.connections.mysql');
        $this->assertIsArray($configuration);
        $this->assertSame('mysql', $configuration['driver'] ?? null);

        $peer = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) ($configuration['host'] ?? '127.0.0.1'),
                (int) ($configuration['port'] ?? 3306),
                (string) $configuration['database'],
            ),
            (string) ($configuration['username'] ?? ''),
            (string) ($configuration['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );

        try {
            DB::table('enterprise_modules')
                ->where('code', 'procurement')
                ->update(['state' => ModuleState::ENABLED->value]);

            $primaryId = (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
            $peerId = (int) $peer->query('SELECT CONNECTION_ID()')->fetchColumn();
            $this->assertGreaterThan(0, $primaryId);
            $this->assertGreaterThan(0, $peerId);
            $this->assertNotSame($primaryId, $peerId, 'Contending sessions must have distinct MySQL connection IDs.');

            // Supplier's transactional lock wins first: a concurrent module
            // shutdown cannot acquire the same row until the quote is done.
            DB::beginTransaction();
            try {
                $locked = DB::table('enterprise_modules')
                    ->where('code', 'procurement')
                    ->lockForUpdate()
                    ->first();
                $this->assertNotNull($locked);
                $this->assertSame(ModuleState::ENABLED->value, $locked->state);
                $this->assertPeerNowaitBlocked($peer);
            } finally {
                DB::rollBack();
            }

            // Admin shutdown now wins first. The independent supplier session
            // cannot lock/approve ENABLED while SUSPENDED is uncommitted.
            $peer->beginTransaction();
            try {
                $read = $peer->prepare('SELECT state FROM enterprise_modules WHERE code = ? FOR UPDATE');
                $read->execute(['procurement']);
                $this->assertSame(ModuleState::ENABLED->value, $read->fetchColumn());
                $change = $peer->prepare('UPDATE enterprise_modules SET state = ? WHERE code = ?');
                $change->execute([ModuleState::SUSPENDED->value, 'procurement']);

                DB::beginTransaction();
                try {
                    $blocked = false;
                    try {
                        DB::select('SELECT state FROM enterprise_modules WHERE code = ? FOR UPDATE NOWAIT', [
                            'procurement',
                        ]);
                    } catch (QueryException $exception) {
                        $blocked = true;
                        $this->assertSame(
                            3572,
                            (int) ($exception->errorInfo[1] ?? 0),
                            'InnoDB NOWAIT must report real conflicting-row lock error 3572.',
                        );
                    }
                    $this->assertTrue($blocked, 'Supplier session must not read an unlocked stale ENABLED state.');
                } finally {
                    DB::rollBack();
                }

                $peer->commit();
            } finally {
                if ($peer->inTransaction()) {
                    $peer->rollBack();
                }
            }

            $this->assertSame(
                ModuleState::SUSPENDED->value,
                DB::table('enterprise_modules')->where('code', 'procurement')->value('state'),
            );
        } finally {
            if ($peer->inTransaction()) {
                $peer->rollBack();
            }
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }

            if ($original === null) {
                DB::table('enterprise_modules')->where('code', 'procurement')->delete();
            } else {
                DB::table('enterprise_modules')
                    ->where('code', 'procurement')
                    ->update(['state' => $original->state]);
            }
        }
    }

    private function assertPeerNowaitBlocked(PDO $peer): void
    {
        $blocked = false;

        try {
            $statement = $peer->prepare(
                'SELECT state FROM enterprise_modules WHERE code = ? FOR UPDATE NOWAIT',
            );
            $statement->execute(['procurement']);
        } catch (PDOException $exception) {
            $blocked = true;
            $this->assertSame(
                3572,
                (int) ($exception->errorInfo[1] ?? 0),
                'InnoDB NOWAIT must report real conflicting-row lock error 3572.',
            );
        }

        $this->assertTrue($blocked, 'Admin session must not bypass the supplier quote lock.');
    }
}
