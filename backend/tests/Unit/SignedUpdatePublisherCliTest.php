<?php

namespace Tests\Unit;

use App\Domain\Updater\SignedUpdateEnvelope;
use App\Domain\Updater\TrustedUpdateSignatureVerifier;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class SignedUpdatePublisherCliTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('sodium') || ! extension_loaded('zip') || ! function_exists('proc_open')) {
            $this->markTestSkipped('CLI publisher integration requires sodium, zip and proc_open.');
        }
        $this->directory = sys_get_temp_dir().'/foodex-signing-'.bin2hex(random_bytes(10));
        if (! mkdir($this->directory, 0700)) {
            $this->fail('Could not create an isolated publisher test directory.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_valid_zip_is_signed_and_installer_verifies_exact_sidecar(): void
    {
        $package = $this->directory.'/update.zip';
        $this->zip($package, ['backend/app/Example.php' => '<?php // update']);
        $pair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($pair));
        $output = $this->directory.'/update.sig.json';

        $result = $this->runPublisher($package, $output, '0', $pair);
        $this->assertSame(0, $result, 'A structurally valid update ZIP should be signed.');
        $this->assertFileExists($output);

        $envelope = SignedUpdateEnvelope::parse((string) file_get_contents($output));
        $envelope->verify(new TrustedUpdateSignatureVerifier, ['test-key' => $public]);
        $this->assertSame(hash_file('sha256', $package), $envelope->manifest->sha256);
        $this->assertFalse($envelope->manifest->containsMigrations);
        $this->assertSame('2.0.0', $envelope->manifest->targetVersion);
    }

    public function test_non_zip_payload_cannot_be_signed_or_leave_a_sidecar(): void
    {
        $package = $this->directory.'/invalid.zip';
        file_put_contents($package, 'not an archive');
        $output = $this->directory.'/invalid.sig.json';

        $this->assertSame(1, $this->runPublisher($package, $output, '0'));
        $this->assertFileDoesNotExist($output);
    }

    public function test_undeclared_migration_cannot_be_signed_or_leave_a_sidecar(): void
    {
        $package = $this->directory.'/migration.zip';
        $this->zip($package, ['backend/database/migrations/2026_01_01_000001_test.php' => '<?php']);
        $output = $this->directory.'/migration.sig.json';

        $this->assertSame(1, $this->runPublisher($package, $output, '0'));
        $this->assertFileDoesNotExist($output);
        $this->assertSame(0, $this->runPublisher($package, $output, '1'));
        $this->assertFileExists($output);
    }

    private function zip(string $path, array $entries): void
    {
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::EXCL) === true);
        foreach ($entries as $name => $data) {
            $this->assertTrue($archive->addFromString($name, $data));
        }
        $this->assertTrue($archive->close());
    }

    private function runPublisher(string $package, string $output, string $migrations, ?string $pair = null): int
    {
        $notes = $this->directory.'/notes.txt';
        file_put_contents($notes, 'Safe test release notes.');
        $pair ??= sodium_crypto_sign_keypair();
        $script = dirname(__DIR__, 3).'/scripts/v2_sign_update_manifest.php';
        $args = [
            PHP_BINARY, $script,
            '--package='.$package,
            '--target-version=2.0.0',
            '--minimum-current-version=1.0.0',
            '--release-notes-file='.$notes,
            '--contains-migrations='.$migrations,
            '--output='.$output,
        ];
        $command = implode(' ', array_map('escapeshellarg', $args));
        $env = array_merge(getenv(), [
            'FOODEX_UPDATE_SIGNING_KEY_ID' => 'test-key',
            'FOODEX_UPDATE_SIGNING_SECRET_KEY_B64' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ]);
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            $env,
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        // Never log signer inputs or subprocess environment when assertions fail.
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return proc_close($process);
    }
}
