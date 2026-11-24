<?php

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Archive-only positive tests use Laravel's real ZIP preflight without
 * running an update. PHPUnit's SQLite :memory: default cannot satisfy the
 * production requirement for a verified database backup. Supply an isolated
 * real SQLite file for that narrow host-readiness check; do not change the
 * production preflight or claim that these tests exercise DB backup/restore.
 */
final class UpdateArchivePreflightFixture
{
    /**
     * @param Closure(): void $assertion
     */
    public static function withBackupableSqlite(Closure $assertion): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $assertion();

            return;
        }

        $oldPath = config('database.connections.sqlite.database');
        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-zip-sqlite-');
        if (! is_string($path)) {
            throw new RuntimeException('Could not allocate positive ZIP preflight SQLite fixture.');
        }

        try {
            $sqlite = new PDO('sqlite:'.$path);
            $sqlite->exec('PRAGMA user_version = 1');
            unset($sqlite);

            config()->set('database.connections.sqlite.database', $path);
            $assertion();
        } finally {
            config()->set('database.connections.sqlite.database', $oldPath);
            @unlink($path);
        }
    }
}
