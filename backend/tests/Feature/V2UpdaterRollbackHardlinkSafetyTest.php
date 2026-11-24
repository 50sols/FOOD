<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class V2UpdaterRollbackHardlinkSafetyTest extends TestCase
{
    public function test_database_restore_rejects_hardlinked_metadata_and_snapshot_before_purging_connection(): void
    {
        if (! function_exists('link')) {
            $this->markTestSkipped('Hardlinks unavailable.');
        }

        $suffix = bin2hex(random_bytes(8));
        $root = storage_path('framework/v2-db-restore-links-'.$suffix);
        $snapshotDirectory = $root.'/database-test';
        $externalMetadata = $root.'/private-database-metadata';
        $metadataPath = $snapshotDirectory.'/database.json';
        $otherMetadataLink = $root.'/metadata-alias';
        $externalSnapshot = $root.'/private-database-snapshot';
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        $filename = match ($driver) {
            'sqlite' => 'database.sqlite',
            'mysql' => 'database.sql',
            'pgsql' => 'database.dump',
            default => null,
        };
        if ($filename === null) {
            $this->markTestSkipped('Database backup provenance test requires a supported DB driver.');
        }

        $snapshotPath = $snapshotDirectory.'/'.$filename;
        $previousRoot = config('foodex.update_backup_path');
        $pdo = \Illuminate\Support\Facades\DB::connection()->getPdo();
        File::ensureDirectoryExists($snapshotDirectory);
        file_put_contents($externalSnapshot, 'outside-database-snapshot-sentinel');
        file_put_contents($externalMetadata, json_encode([
            'driver' => $driver,
            'snapshot' => $snapshotPath,
        ], JSON_THROW_ON_ERROR));
        config()->set('foodex.update_backup_path', $root);

        try {
            if (! @link($externalMetadata, $metadataPath)) {
                $this->markTestSkipped('Filesystem does not permit database metadata hardlinks.');
            }

            $runtime = app(LaravelUpdateRuntime::class);
            try {
                $runtime->rollbackDatabase($snapshotDirectory);
                $this->fail('A multiply linked database metadata inode must not authorize a restore.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('metadata is invalid or unsafe', $exception->getMessage());
            }

            $this->assertSame($pdo, \Illuminate\Support\Facades\DB::connection()->getPdo());
            $this->assertSame('outside-database-snapshot-sentinel', file_get_contents($externalSnapshot));

            unlink($metadataPath);
            copy($externalMetadata, $metadataPath);
            if (! @link($externalSnapshot, $snapshotPath)) {
                $this->markTestSkipped('Filesystem does not permit database snapshot hardlinks.');
            }

            try {
                $runtime->rollbackDatabase($snapshotDirectory);
                $this->fail('A multiply linked database snapshot inode must not authorize a restore.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('snapshot is missing or unsafe', $exception->getMessage());
            }

            $this->assertSame($pdo, \Illuminate\Support\Facades\DB::connection()->getPdo());
            $this->assertSame('outside-database-snapshot-sentinel', file_get_contents($externalSnapshot));
        } finally {
            config()->set('foodex.update_backup_path', $previousRoot);
            File::deleteDirectory($root);
        }
    }

    public function test_file_backup_rejects_hardlinked_release_source_before_copying_foreign_bytes(): void
    {
        if (! function_exists('link') || ! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('Hardlinks or ZIP support unavailable.');
        }

        $suffix = bin2hex(random_bytes(8));
        $name = 'v2-backup-hardlink-'.$suffix.'.txt';
        $relative = 'backend/tests/'.$name;
        $liveTarget = base_path('tests/'.$name);
        $external = storage_path('framework/'.$name);
        $backupRoot = storage_path('framework/v2-hardlink-backup-'.$suffix);
        $zipPath = storage_path('framework/v2-hardlink-bundle-'.$suffix.'.zip');
        $originalBackupRoot = config('foodex.update_backup_path');

        File::ensureDirectoryExists(dirname($external));
        File::ensureDirectoryExists($backupRoot);
        file_put_contents($external, 'external-sensitive-sentinel-'.$suffix);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        $this->assertTrue($zip->addFromString($relative, 'future-release-content'));
        $this->assertTrue($zip->close());

        config()->set('foodex.update_backup_path', $backupRoot);

        try {
            if (! @link($external, $liveTarget)) {
                $this->markTestSkipped('Filesystem does not permit cross-directory hardlinks.');
            }

            try {
                app(LaravelUpdateRuntime::class)->backupFiles($zipPath);
                $this->fail('A hardlinked live release source must not be copied into private updater backups.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('hardlinked or unsafe', $exception->getMessage());
            }

            $this->assertSame('external-sensitive-sentinel-'.$suffix, file_get_contents($external));
            $this->assertSame('external-sensitive-sentinel-'.$suffix, file_get_contents($liveTarget));

            // The failed backup may create an empty snapshot directory, but
            // must never persist a copy of the external private inode bytes.
            $this->assertSame([], File::allFiles($backupRoot));

            // A normal, single-link release file still yields a verifiable
            // snapshot using the same signed package entry list.
            unlink($liveTarget);
            file_put_contents($liveTarget, 'legitimate-original-release');
            $backup = app(LaravelUpdateRuntime::class)->backupFiles($zipPath);
            $this->assertSame(
                'legitimate-original-release',
                file_get_contents($backup.'/existing/'.$relative),
            );
        } finally {
            config()->set('foodex.update_backup_path', $originalBackupRoot);
            @unlink($liveTarget);
            @unlink($external);
            @unlink($zipPath);
            File::deleteDirectory($backupRoot);
        }
    }

    public function test_rollback_rejects_hardlinked_backup_member_and_manifest_before_touching_release(): void
    {
        if (! function_exists('link')) {
            $this->markTestSkipped('Hard links are unavailable.');
        }

        $root = sys_get_temp_dir().'/foodex-rollback-links-'.bin2hex(random_bytes(7));
        $name = 'rollback-links-'.bin2hex(random_bytes(7)).'.txt';
        $relative = 'backend/tests/'.$name;
        $target = base_path('tests/'.$name);
        $snapshot = $root.'/snapshot-files';
        $source = $snapshot.'/existing/'.$relative;
        $externalFile = $root.'/private-backup-origin';
        $manifestPath = $snapshot.'/files.json';
        $manifestOtherLink = $root.'/external-manifest-link';
        $previousBackupPath = config('foodex.update_backup_path');

        File::ensureDirectoryExists(dirname($target));
        File::ensureDirectoryExists(dirname($source));
        file_put_contents($target, 'live-release-bytes');
        file_put_contents($externalFile, 'trusted-backup-bytes');

        config()->set('foodex.update_backup_path', $root);

        try {
            $manifest = [
                'targets' => [$relative],
                'existing' => [$relative],
                'file_snapshot_format' => 1,
                'file_sha256' => (object) [$relative => hash_file('sha256', $externalFile)],
            ];
            file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

            if (! @link($externalFile, $source)) {
                $this->markTestSkipped('Filesystem cannot create hard links for recovery regression.');
            }

            $runtime = app(LaravelUpdateRuntime::class);
            try {
                $runtime->rollbackFiles($snapshot);
                $this->fail('A hardlinked backup source must not be copied into the live release.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('incomplete or unsafe', $exception->getMessage());
            }

            $this->assertSame('live-release-bytes', file_get_contents($target));
            $this->assertSame('trusted-backup-bytes', file_get_contents($externalFile));

            unlink($source);
            copy($externalFile, $source);

            if (! @link($manifestPath, $manifestOtherLink)) {
                $this->markTestSkipped('Filesystem cannot create a hardlinked rollback manifest.');
            }

            try {
                $runtime->rollbackFiles($snapshot);
                $this->fail('A hardlinked manifest must not authorize deletion of the live release.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('manifest is missing or unsafe', $exception->getMessage());
            }
            $this->assertSame('live-release-bytes', file_get_contents($target));

            // A real, single-link backup with the same verified digest is
            // still restorable after the two denial conditions are removed.
            unlink($manifestOtherLink);
            $runtime->rollbackFiles($snapshot);
            $this->assertSame('trusted-backup-bytes', file_get_contents($target));
        } finally {
            config()->set('foodex.update_backup_path', $previousBackupPath);
            @unlink($target);
            File::deleteDirectory($root);
        }
    }
}
