<?php

namespace Tests\Unit;

use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdatePackageValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class UpdatePackageValidatorTest extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packagePath = tempnam(sys_get_temp_dir(), 'foodex-update-');
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('The ZIP extension is required for package validation.');
        }

        $this->createZipPackage(['release-notes.txt']);
    }

    protected function tearDown(): void
    {
        @unlink($this->packagePath);
        parent::tearDown();
    }

    public function test_valid_package_passes_integrity_and_compatibility_checks(): void
    {
        $manifest = new UpdatePackageManifest('1.2.0', '1.0.0', hash_file('sha256', $this->packagePath), 'Release', true);
        $result = (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
        $this->assertSame('1.2.0', $result['target_version']);
        $this->assertTrue($result['contains_migrations']);
    }

    public function test_embedded_version_must_match_signed_target_version(): void
    {
        // The SHA is recalculated for each complete, valid ZIP. Mismatched
        // VERSION would be authenticated by a signer, but cannot represent
        // the advertised software version on disk.
        foreach ([
            ['VERSION', "1.2.1\n", 'does not match signed target version'],
            ['VERSION', "1.2.0 \n", 'does not match signed target version'],
            ['VERSION', str_repeat('1', 129), 'invalid embedded VERSION'],
            ['version', "1.2.0\n", 'invalid embedded VERSION'],
        ] as [$filename, $content, $expected]) {
            $archive = new \ZipArchive;
            $this->assertTrue($archive->open($this->packagePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
            $this->assertTrue($archive->addFromString('backend/app/Release.php', '<?php // release'));
            $this->assertTrue($archive->addFromString($filename, $content));
            $this->assertTrue($archive->close());

            $manifest = new UpdatePackageManifest(
                '1.2.0', '1.0.0', hash_file('sha256', $this->packagePath),
                'Actual ZIP VERSION mismatch', false,
            );
            try {
                (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
                $this->fail('Mismatched signed target/version ZIP must not verify.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($expected, $exception->getMessage());
            }
        }
    }

    public function test_exact_signed_version_file_remains_installable(): void
    {
        foreach (['1.2.0', "1.2.0\n", "1.2.0\r\n"] as $contents) {
            $archive = new \ZipArchive;
            $this->assertTrue($archive->open($this->packagePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
            $this->assertTrue($archive->addFromString('backend/app/Release.php', '<?php // release'));
            $this->assertTrue($archive->addFromString('VERSION', $contents));
            $this->assertTrue($archive->close());

            $manifest = new UpdatePackageManifest(
                '1.2.0', '1.0.0', hash_file('sha256', $this->packagePath),
                'Valid source release marker', false,
            );
            $verified = (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
            $this->assertSame('1.2.0', $verified['target_version']);
        }
    }

    public function test_matching_digest_cannot_verify_directory_only_zip(): void
    {
        // A well-formed ZIP with directory entries passes the member-count
        // check but cannot install any release source. Verification and the
        // runtime extractor must agree before backups or maintenance begin.
        $zip = new \ZipArchive;
        $this->assertTrue(
            $zip->open($this->packagePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true,
        );
        $this->assertTrue($zip->addEmptyDir('backend'));
        $this->assertTrue($zip->addEmptyDir('backend/app'));
        $this->assertTrue($zip->close());

        $manifest = new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            hash_file('sha256', $this->packagePath),
            'Archive with directories but no deployable release files',
            true,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no release files');
        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    public function test_matching_digest_cannot_verify_an_empty_but_well_formed_zip(): void
    {
        // The ZIP End of Central Directory record is a complete valid
        // zero-member ZIP; a publisher must not sign a deployable-looking
        // version bump that contains no release files.
        file_put_contents($this->packagePath, "PK\x05\x06".str_repeat("\x00", 18));

        $manifest = new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            hash_file('sha256', $this->packagePath),
            'Empty but correctly checksummed ZIP',
            true,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no release members');
        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    public function test_matching_checksum_does_not_authorize_a_non_zip_update_package(): void
    {
        file_put_contents($this->packagePath, 'This is not a ZIP archive.');

        $manifest = new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            hash_file('sha256', $this->packagePath),
            'Invalid release',
            false,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a readable ZIP archive');

        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    public function test_truncated_zip_is_rejected_even_when_manifest_hash_matches(): void
    {
        $this->createZipPackage(['backend/app/Example.php']);
        $contents = file_get_contents($this->packagePath);
        $this->assertIsString($contents);
        $this->assertGreaterThan(30, strlen($contents));
        file_put_contents($this->packagePath, substr($contents, 0, 24));

        $manifest = new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            hash_file('sha256', $this->packagePath),
            'Truncated release',
            false,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a readable ZIP archive');

        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    public function test_a_matching_digest_cannot_verify_unsafe_zip_member_paths(): void
    {
        $unsafeArchives = [
            ['../escape.php'],
            ['/absolute.php'],
            ['backend//duplicate-separator.php'],
            ['backend/./dot-segment.php'],
            ['backend/app/../escape.php'],
            ['backend\app\legacy.php'],
            ['backend/CON.php'],
            ['backend/trailing-space. '],
            ['backend/App.php', 'backend/app.php'],
            ['backend/app.php', 'backend/app.php/child.php'],
        ];

        foreach ($unsafeArchives as $paths) {
            $this->createZipPackage($paths);
            $manifest = new UpdatePackageManifest(
                '1.2.0', '1.0.0',
                hash_file('sha256', $this->packagePath),
                'A SHA-256-matched archive with an unsafe member',
                true,
            );

            try {
                (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
                $this->fail('Unsafe ZIP member unexpectedly passed package verification: '.implode(', ', $paths));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Update archive contains', $exception->getMessage());
            }
        }
    }

    public function test_matching_digest_does_not_verify_unix_symlink_zip_member(): void
    {
        $this->createZipPackage(['backend/linked.php']);
        $archive = new \ZipArchive;
        $this->assertTrue($archive->open($this->packagePath) === true);
        $this->assertTrue($archive->setExternalAttributesName(
            'backend/linked.php',
            \ZipArchive::OPSYS_UNIX,
            (0120777 << 16),
        ));
        $this->assertTrue($archive->close());

        $manifest = new UpdatePackageManifest(
            '1.2.0', '1.0.0',
            hash_file('sha256', $this->packagePath),
            'Symlink must not be verified',
            true,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsafe non-regular member');
        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    public function test_matching_digest_cannot_verify_protected_live_data_or_executable_public_paths(): void
    {
        // The extractor already denies these targets. A valid SHA-256 must
        // not let Verify Package or the release signer approve them first.
        $unsafe = [
            'backend/.env' => 'protected runtime path',
            'backend/.ENV.production' => 'protected runtime path',
            'backend/.env.example/child.php' => 'protected runtime path',
            '.env.local' => 'protected runtime path',
            'backend/storage/app/private/customer.csv' => 'protected runtime path',
            'backend/vendor/autoload.php' => 'protected runtime path',
            'backend/.git/config' => 'protected runtime path',
            'assets/shell.php.jpg' => 'executable public asset path',
            'brand/.htaccess.extra' => 'executable public asset path',
            'preview/web.config' => 'executable public asset path',
            'backend/public/evil.phtml' => 'executable public asset path',
            'backend/public/.user.ini' => 'executable public asset path',
        ];

        foreach ($unsafe as $path => $reason) {
            $this->createZipPackage([$path]);
            $manifest = new UpdatePackageManifest(
                '1.2.0', '1.0.0',
                hash_file('sha256', $this->packagePath),
                'Signed update should not overwrite live configuration', true,
            );

            try {
                (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
                $this->fail('Protected or executable update target was verified: '.$path);
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($reason, $exception->getMessage(), $path);
            }
        }
    }

    public function test_source_templates_and_canonical_laravel_public_bootstrap_remain_verifiable(): void
    {
        // A fresh deploy can legitimately update examples, versioned static
        // assets and Laravel's exact front controller/rewrite configuration.
        $this->createZipPackage([
            'backend/.env.example',
            'backend/.env.testing.example',
            'backend/public/index.php',
            'backend/public/.htaccess',
            'assets/app.css',
            'backend/app/Release.php',
        ]);

        $manifest = new UpdatePackageManifest(
            '1.2.0', '1.0.0',
            hash_file('sha256', $this->packagePath),
            'Safe source and public bootstrap update', false,
        );

        $result = (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
        $this->assertSame('1.2.0', $result['target_version']);
    }

    public function test_migration_zip_cannot_bypass_database_backup_when_manifest_flag_is_false(): void
    {
        $this->createZipPackage(['backend/database/migrations/2026_11_13_000000_test.php']);

        $manifest = new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            hash_file('sha256', $this->packagePath),
            'Migration bundle',
            false,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains migrations');

        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    public function test_backslash_migration_paths_cannot_bypass_backup_declaration(): void
    {
        $this->createZipPackage(['backend\database\migrations\2026_11_13_000000_test.php']);

        $manifest = new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            hash_file('sha256', $this->packagePath),
            'Windows path migration bundle',
            false,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains migrations');

        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    public function test_migration_zip_with_declared_database_backup_passes_validator(): void
    {
        $this->createZipPackage(['backend/database/migrations/2026_11_13_000000_test.php']);

        $manifest = new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            hash_file('sha256', $this->packagePath),
            'Migration bundle',
            true,
        );

        $result = (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);

        $this->assertTrue($result['contains_migrations']);
        $this->assertSame('1.2.0', $result['target_version']);
    }

    public function test_legacy_compact_installed_version_is_normalized_for_upgrade_comparison(): void
    {
        $manifest = new UpdatePackageManifest('1.0.85', '1.0.84', hash_file('sha256', $this->packagePath), 'Release', false);

        $result = (new UpdatePackageValidator)->validate($manifest, '1.084', $this->packagePath);

        $this->assertSame('1.0.84', $result['current_version']);
        $this->assertSame('1.0.85', $result['target_version']);
    }

    public function test_noncanonical_manifest_version_is_rejected(): void
    {
        $manifest = new UpdatePackageManifest('1.086', '1.0.84', hash_file('sha256', $this->packagePath), 'Release', false);

        $this->expectException(InvalidArgumentException::class);
        (new UpdatePackageValidator)->validate($manifest, '1.0.84', $this->packagePath);
    }

    public function test_tampered_package_is_rejected(): void
    {
        $manifest = new UpdatePackageManifest('1.2.0', '1.0.0', str_repeat('a', 64), 'Release', false);
        $this->expectException(InvalidArgumentException::class);
        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    public function test_incompatible_current_version_is_rejected(): void
    {
        $manifest = new UpdatePackageManifest('2.0.0', '1.5.0', hash_file('sha256', $this->packagePath), 'Release', false);
        $this->expectException(InvalidArgumentException::class);
        (new UpdatePackageValidator)->validate($manifest, '1.4.9', $this->packagePath);
    }

    public function test_downgrade_or_same_version_is_rejected(): void
    {
        $manifest = new UpdatePackageManifest('1.1.0', '1.0.0', hash_file('sha256', $this->packagePath), 'Release', false);
        $this->expectException(InvalidArgumentException::class);
        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function createZipPackage(array $paths): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('The ZIP extension is required.');
        }

        $zip = new \ZipArchive;
        $this->assertTrue(
            $zip->open($this->packagePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true,
        );

        foreach ($paths as $path) {
            $this->assertTrue($zip->addFromString($path, '<?php // migration fixture'));
        }

        $this->assertTrue($zip->close());
    }
}
