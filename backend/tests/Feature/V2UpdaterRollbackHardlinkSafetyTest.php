<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class V2UpdaterRollbackHardlinkSafetyTest extends TestCase
{
    public function test_rollback_rejects_hardlinked_backup_member_and_manifest_before_touching_release(): void
    {
        if (! function_exists('link')) {
            $this->markTestSkipped('Hard links are unavailable.');
        }

        $root = sys_get_temp_dir().'/foodex-rollback-links-'.bin2hex(random_bytes(7));
        $name = 'rollback-links-'.bin2hex(random_bytes(7)).'.txt';
        $relative = 'backend/storage/framework/'.$name;
        $target = storage_path('framework/'.$name);
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
