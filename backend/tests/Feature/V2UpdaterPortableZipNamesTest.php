<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\UpdatePackageManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdaterPortableZipNamesTest extends TestCase
{
    private string $releaseName;

    private string $releaseDirectory;

    private string $backupDirectory;

    /** @var list<string> */
    private array $archives = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension required for updater security regressions.');
        }

        $this->releaseName = 'v2-zip-portable-'.bin2hex(random_bytes(6));
        $this->releaseDirectory = dirname(base_path()).'/'.$this->releaseName;
        $this->backupDirectory = storage_path('framework/testing/v2-zip-portable-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->releaseDirectory);
        File::ensureDirectoryExists($this->backupDirectory);
        config()->set('foodex.update_backup_path', $this->backupDirectory);
        file_put_contents($this->releaseDirectory.'/sentinel.txt', 'UNCHANGED');
    }

    protected function tearDown(): void
    {
        foreach ($this->archives as $archive) {
            @unlink($archive);
        }

        if (isset($this->releaseDirectory)) {
            File::deleteDirectory($this->releaseDirectory);
        }

        if (isset($this->backupDirectory)) {
            File::deleteDirectory($this->backupDirectory);
        }

        parent::tearDown();
    }

    public function test_unicode_file_name_equivalences_are_rejected_in_both_zip_orders_and_all_entrypoints(): void
    {
        $cases = [
            ['Straße.txt', 'STRASSE.txt'],
            ['STRASSE.txt', 'Straße.txt'],
            ['café.txt', "cafe\u{0301}.txt"],
            ["cafe\u{0301}.txt", 'café.txt'],
            ['MÜNCHEN.txt', 'münchen.txt'],
            ['münchen.txt', 'MÜNCHEN.txt'],
            ['CAFÉ/one.php', "cafe\u{0301}/two.php"],
            ["cafe\u{0301}/two.php", 'CAFÉ/one.php'],
            ['Nested/MÜNCHEN.txt', 'nested/münchen.txt'],
            ['nested/münchen.txt', 'Nested/MÜNCHEN.txt'],
        ];

        foreach ($cases as $files) {
            $this->assertRejectsBeforeMutation(
                array_fill_keys(array_map(fn (string $path): string => $this->releaseName.'/'.$path, $files), 'unsafe'),
                'Update archive contains an unsafe path.',
            );
        }
    }

    public function test_single_unicode_file_and_unicode_directory_entry_are_both_rejected(): void
    {
        foreach (["\t".$this->releaseName.'/target.txt', $this->releaseName."/a\x01b.txt"] as $name) {
            $this->assertRejectsBeforeMutation([$name => 'invalid'], 'Update archive contains an unsafe path.');
        }

        $this->assertRejectsBeforeMutation([
            $this->releaseName.'/naïve.txt' => 'invalid',
        ], 'Update archive contains an unsafe path.');

        $this->assertRejectsBeforeMutation([
            $this->releaseName.'/MÜNCHEN/' => '',
        ], 'Update archive contains an unsafe path.');

        $this->assertRejectsBeforeMutation([
            $this->releaseName."/control\x01/" => '',
        ], 'Update archive contains an unsafe path.');
    }

    public function test_non_utf8_raw_zip_member_name_is_rejected_before_mutation(): void
    {
        $this->assertRejectsBeforeMutation([
            $this->releaseName."/caf\x82.txt" => 'invalid',
        ], 'Update archive contains an unsafe path.');
    }

    public function test_existing_alias_traversal_dotenv_and_shadow_guards_survive(): void
    {
        foreach ([
            [
                [$this->releaseName.'/Foo.php', $this->releaseName.'/foo.php'],
                'Update archive contains duplicate release target paths.',
            ],
            [
                [$this->releaseName.'/foo.php', $this->releaseName.'/Foo.php'],
                'Update archive contains duplicate release target paths.',
            ],
            [
                [$this->releaseName.'/Box/', $this->releaseName.'/box'],
                'Update archive contains a file/directory target conflict.',
            ],
            [
                [$this->releaseName.'/box', $this->releaseName.'/Box/'],
                'Update archive contains a file/directory target conflict.',
            ],
            [
                [$this->releaseName.'/Mod', $this->releaseName.'/mod/Child.php'],
                'Update archive contains a file/directory target conflict.',
            ],
            [
                [$this->releaseName.'/mod/Child.php', $this->releaseName.'/Mod'],
                'Update archive contains a file/directory target conflict.',
            ],
            [
                [$this->releaseName.'/Mod', $this->releaseName.'/mod/Child/'],
                'Update archive contains a file/directory target conflict.',
            ],
            [
                [$this->releaseName.'/mod/Child/', $this->releaseName.'/Mod'],
                'Update archive contains a file/directory target conflict.',
            ],
            [
                [$this->releaseName.'/Alpha/Beta', $this->releaseName.'/alpha/beta/Deep/'],
                'Update archive contains a file/directory target conflict.',
            ],
            [
                [$this->releaseName.'/alpha/beta/Deep/', $this->releaseName.'/Alpha/Beta'],
                'Update archive contains a file/directory target conflict.',
            ],
        ] as [$names, $message]) {
            $this->assertRejectsBeforeMutation(array_fill_keys($names, 'content'), $message);
        }

        foreach ([
            $this->releaseName.'/../../outside.txt',
            $this->releaseName.'/vendor./autoload.php',
            $this->releaseName.'/app /Widget.php',
        ] as $path) {
            $this->assertRejectsBeforeMutation([$path => 'content'], 'Update archive contains an unsafe path.');
        }

        $this->assertRejectsBeforeMutation(
            ['backend/.Env.Production' => 'credential'],
            'Update archive targets a protected runtime path.',
        );

        $this->assertRejectsBeforeMutation(
            [$this->releaseName.'/linked.php' => 'target'],
            'Update archive contains a symbolic link.',
            $this->releaseName.'/linked.php',
        );
    }

    public function test_portable_distinct_zip_is_accepted_and_backup_and_rollback_are_lossless(): void
    {
        $package = $this->createArchive([
            $this->releaseName.'/sentinel.txt' => 'تحديث café',
            $this->releaseName.'/alpha.php' => 'alpha',
            $this->releaseName.'/Nested/' => '',
            $this->releaseName.'/Nested/bravo.php' => 'bravo',
        ]);
        $runtime = app(LaravelUpdateRuntime::class);
        $runtime->preflight($this->manifest($package), $package);
        $backup = $runtime->backupFiles($package);
        $this->assertFileExists($backup.'/files.json');
        $runtime->extractRelease($package);

        $this->assertSame('تحديث café', file_get_contents($this->releaseDirectory.'/sentinel.txt'));
        $this->assertSame('alpha', file_get_contents($this->releaseDirectory.'/alpha.php'));
        $this->assertSame('bravo', file_get_contents($this->releaseDirectory.'/Nested/bravo.php'));

        $runtime->rollbackFiles($backup);

        $this->assertSame('UNCHANGED', file_get_contents($this->releaseDirectory.'/sentinel.txt'));
        $this->assertFileDoesNotExist($this->releaseDirectory.'/alpha.php');
        $this->assertFileDoesNotExist($this->releaseDirectory.'/Nested/bravo.php');

        $example = $this->createArchive([
            'backend/.env.example' => 'template',
            'backend/.env.production.example' => 'template',
        ]);
        $runtime->preflight($this->manifest($example), $example);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function assertRejectsBeforeMutation(array $files, string $message, ?string $symlink = null): void
    {
        $package = $this->createArchive([
            $this->releaseName.'/sentinel.txt' => 'ATTEMPTED OVERWRITE',
            ...$files,
        ], $symlink);
        $runtime = app(LaravelUpdateRuntime::class);

        $connection = DB::connection();
        $connection->enableQueryLog();
        foreach (['preflight', 'backupFiles', 'extractRelease'] as $operation) {
            $connection->flushQueryLog();
            try {
                if ($operation === 'preflight') {
                    $runtime->preflight($this->manifest($package), $package);
                } else {
                    $runtime->{$operation}($package);
                }
                $this->fail('Unsafe ZIP accepted by '.$operation);
            } catch (RuntimeException $exception) {
                $this->assertSame($message, $exception->getMessage(), $operation);
            }

            $this->assertSame('UNCHANGED', file_get_contents($this->releaseDirectory.'/sentinel.txt'));
            $this->assertSame([], glob($this->backupDirectory.'/*') ?: []);
            $this->assertSame(['sentinel.txt'], array_values(array_diff(scandir($this->releaseDirectory), ['.', '..'])));
            $this->assertSame([], $connection->getQueryLog());
        }
    }

    /** @param  array<string, string>  $files */
    private function createArchive(array $files, ?string $symlink = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foodex-zip-portable-');
        if (! is_string($path)) {
            throw new RuntimeException('Could not allocate ZIP regression fixture.');
        }

        $this->archives[] = $path;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($files as $name => $contents) {
            $this->assertTrue(str_ends_with($name, '/')
                ? $zip->addEmptyDir($name)
                : $zip->addFromString($name, $contents));
            if ($name === $symlink) {
                $this->assertTrue($zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16));
            }
        }

        $this->assertTrue($zip->close());

        return $path;
    }

    private function manifest(string $package): UpdatePackageManifest
    {
        return new UpdatePackageManifest('99.0.2', '99.0.1', hash_file('sha256', $package), 'Portable ZIP path regression', false);
    }
}
