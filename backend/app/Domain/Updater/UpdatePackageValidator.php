<?php

namespace App\Domain\Updater;

use InvalidArgumentException;

final class UpdatePackageValidator
{
    public function validate(UpdatePackageManifest $manifest, string $currentVersion, string $packagePath): array
    {
        $currentVersion = UpdateVersion::normalizeInstalled($currentVersion);

        if (! UpdateVersion::isCanonical($currentVersion)) {
            throw new InvalidArgumentException('Installed version is not a canonical major.minor.patch version.');
        }

        if (! UpdateVersion::isCanonical($manifest->targetVersion)
            || ! UpdateVersion::isCanonical($manifest->minimumCurrentVersion)) {
            throw new InvalidArgumentException('Update versions must use canonical major.minor.patch format.');
        }

        $this->rejectSymbolicLinkPackagePath($packagePath);

        // An uploaded ZIP must own its inode. A hardlink can alias a foreign
        // private file without triggering symlink or realpath checks; refuse
        // it before reading its contents for the checksum or ZIP parser.
        clearstatcache(true, $packagePath);
        $packageStat = @lstat($packagePath);
        if ($packageStat !== false
            && (($packageStat['mode'] & 0170000) !== 0100000 || $packageStat['nlink'] !== 1)) {
            throw new InvalidArgumentException('Update package file is hardlinked or unsafe.');
        }

        if (! is_file($packagePath) || ! is_readable($packagePath)) {
            throw new InvalidArgumentException('Update package is unavailable.');
        }

        if (! preg_match('/^[a-f0-9]{64}$/i', $manifest->sha256)) {
            throw new InvalidArgumentException('Update package hash is invalid.');
        }

        $actualHash = hash_file('sha256', $packagePath);
        if (! hash_equals(strtolower($manifest->sha256), strtolower((string) $actualHash))) {
            throw new InvalidArgumentException('Update package integrity check failed.');
        }

        // A matching SHA-256 does not prove that the package is a valid ZIP.
        // Verify its structure before reporting success; real runtime preflight
        // must never be the first phase that discovers a corrupt archive.
        if (! class_exists(\ZipArchive::class)) {
            throw new InvalidArgumentException('ZIP extension is required to validate update packages.');
        }

        $archive = new \ZipArchive;
        if ($archive->open($packagePath) !== true) {
            throw new InvalidArgumentException('Update package is not a readable ZIP archive.');
        }

        try {
            // Standalone PHPUnit unit tests exercise this validator
            // without booting Laravel's config repository.
            $configuredMaxMembers = app()->bound('config')
                ? config('foodex.update_max_zip_members', 50000)
                : 50000;
            $maxMembers = max(1, (int) $configuredMaxMembers);
            // Verify Package must apply the SAME expansion budgets as the
            // runtime preflight; otherwise a signed ZIP can look installable
            // until the operator starts maintenance and backups.
            $maxEntryBytes = max(1, (int) (app()->bound('config')
                ? config('foodex.update_max_entry_uncompressed_bytes', 268435456)
                : 268435456));
            $maxTotalBytes = max(1, (int) (app()->bound('config')
                ? config('foodex.update_max_total_uncompressed_bytes', 1073741824)
                : 1073741824));
            $expandedBytes = 0;
            if ($archive->numFiles === 0) {
                throw new InvalidArgumentException('Update archive contains no release members.');
            }
            if ($archive->numFiles > $maxMembers) {
                throw new InvalidArgumentException('Update archive exceeds the allowed member count.');
            }

            // A valid archive checksum does not make arbitrary ZIP members safe.
            // The verify step must fail before it labels a traversal, symlink or
            // portable-name alias as an installable update. The publisher and
            // runtime preflight retain their independent enforcement.
            $knownPaths = [];
            $filePaths = [];
            $hasReleaseFile = false;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $name = $archive->getNameIndex($index);
                if (! is_string($name)) {
                    throw new InvalidArgumentException('Update archive contains an unreadable member name.');
                }

                $memberStat = $archive->statIndex($index);
                $memberBytes = is_array($memberStat) ? $memberStat['size'] : null;
                if (! is_int($memberBytes) || $memberBytes < 0) {
                    throw new InvalidArgumentException('Update archive entry length could not be read.');
                }

                // Subtraction avoids overflow from a malicious ZIP header.
                if ($memberBytes > $maxEntryBytes || $memberBytes > $maxTotalBytes - $expandedBytes) {
                    throw new InvalidArgumentException('Update archive exceeds the allowed expanded size.');
                }
                $expandedBytes += $memberBytes;

                // Preserve the migration-declaration guard, including legacy
                // Windows-style member spelling, before rejecting its alias.
                $migrationPath = strtolower(str_replace('\\', '/', trim($name)));
                if (! $manifest->containsMigrations
                    && str_starts_with($migrationPath, 'backend/database/migrations/')
                    && str_ends_with($migrationPath, '.php')) {
                    throw new InvalidArgumentException(
                        'Update package contains migrations but database backup was not declared.'
                    );
                }

                if ($name === ''
                    || $archive->getNameIndex($index, \ZipArchive::FL_ENC_RAW) !== $name
                    || str_contains($name, '\\')
                    || preg_match('~\A(?:/|[A-Za-z]:)|(?:\A|/)\.{1,2}(?:/|\z)|//|[\x00-\x1F\x7F]~D', $name) === 1
                    || preg_match('/[^\x20-\x7E]/', $name) === 1) {
                    throw new InvalidArgumentException('Update archive contains an unsafe member path.');
                }

                $isDirectory = str_ends_with($name, '/');
                $relativePath = $isDirectory ? substr($name, 0, -1) : $name;
                $components = explode('/', $relativePath);
                foreach ($components as $component) {
                    if ($component === ''
                        || rtrim($component, ' .') !== $component
                        || preg_match('/[<>:"|?*]/', $component) === 1
                        || preg_match('/\A(?:con|conin\$|conout\$|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?\z/iD', $component) === 1) {
                        throw new InvalidArgumentException('Update archive contains an unsafe portable member name.');
                    }
                }

                // Package verification and build-host publishing must not
                // mark a signed ZIP as installable when runtime preflight
                // would later refuse its protected target. Match the canonical
                // LaravelUpdateRuntime::normalizeEntry release-path policy.
                $this->assertUnprotectedReleaseTarget($relativePath);

                $foldedPath = strtolower($relativePath);
                if (isset($knownPaths[$foldedPath])) {
                    throw new InvalidArgumentException('Update archive contains ambiguous member aliases.');
                }
                $knownPaths[$foldedPath] = true;
                if (! $isDirectory) {
                    $filePaths[$foldedPath] = true;
                    $hasReleaseFile = true;
                }

                $operatingSystem = 0;
                $attributes = 0;
                if ($archive->getExternalAttributesIndex($index, $operatingSystem, $attributes)
                    && $operatingSystem === \ZipArchive::OPSYS_UNIX) {
                    $entryType = ($attributes >> 16) & 0170000;
                    if ($entryType === 0120000
                        || ($entryType !== 0 && $entryType !== 0100000
                            && ! ($entryType === 0040000 && $isDirectory))) {
                        throw new InvalidArgumentException('Update archive contains an unsafe non-regular member.');
                    }
                }
            }

            if (! $hasReleaseFile) {
                throw new InvalidArgumentException('Update archive contains no release files.');
            }

            // An authenticated checksum alone cannot make a ZIP with a
            // conflicting VERSION a valid target release. Source-built Update
            // ZIPs contain root VERSION; compare its actual bytes with the
            // signed target before Verify Package reports an installable ZIP.
            // Historical partial code-only ZIPs with no VERSION remain valid;
            // they are not evidence of a full source-built release.
            $versionIndex = $archive->locateName('VERSION', \ZipArchive::FL_NOCASE);
            if ($versionIndex !== false) {
                $versionName = $archive->getNameIndex($versionIndex);
                $versionStat = $archive->statIndex($versionIndex);
                if ($versionName !== 'VERSION' || ! is_array($versionStat)
                    || $versionStat['size'] > 128) {
                    throw new InvalidArgumentException('Update archive contains an invalid embedded VERSION.');
                }

                $embeddedVersion = $archive->getFromIndex($versionIndex, 129);
                if (! is_string($embeddedVersion)
                    || ! in_array($embeddedVersion, [
                        $manifest->targetVersion,
                        $manifest->targetVersion."\n",
                        $manifest->targetVersion."\r\n",
                    ], true)) {
                    throw new InvalidArgumentException('Update archive VERSION does not match signed target version.');
                }
            }

            // Files cannot also be parent directories, even on case-insensitive
            // or shared-hosting filesystems with different normalization rules.
            foreach ($knownPaths as $path => $_) {
                $parts = explode('/', $path);
                array_pop($parts);
                while ($parts !== []) {
                    if (isset($filePaths[implode('/', $parts)])) {
                        throw new InvalidArgumentException('Update archive contains a file/directory collision.');
                    }
                    array_pop($parts);
                }
            }
        } finally {
            $archive->close();
        }

        if (UpdateVersion::compareCanonical($currentVersion, $manifest->minimumCurrentVersion) < 0) {
            throw new InvalidArgumentException('Current version is not supported by this update.');
        }

        if (UpdateVersion::compareCanonical($manifest->targetVersion, $currentVersion) <= 0) {
            throw new InvalidArgumentException('Target version must be newer than the installed version.');
        }

        return [
            'current_version' => $currentVersion,
            'target_version' => $manifest->targetVersion,
            'package_hash' => strtolower($manifest->sha256),
            'contains_migrations' => $manifest->containsMigrations,
        ];
    }

    /**
     * Reject update members that would replace live data/secrets or introduce
     * executable public assets before the ZIP is verified or signed.
     * This mirrors LaravelUpdateRuntime::normalizeEntry; runtime independently
     * rechecks each target immediately before backup and extraction.
     */
    private function assertUnprotectedReleaseTarget(string $relativePath): void
    {
        $components = explode('/', $relativePath);
        $lastIndex = array_key_last($components);

        foreach ($components as $index => $component) {
            $isExampleTemplate = $index === $lastIndex
                && preg_match('/^\\.env(?:\\.[A-Za-z0-9_-]+)*\\.example$/', $component) === 1;

            if (preg_match('/^\\.env(?:\\..*)?$/i', $component) === 1 && ! $isExampleTemplate) {
                throw new InvalidArgumentException('Update archive targets a protected runtime path.');
            }
        }

        if (preg_match('#(^|/)(storage|vendor|\\.git)(/|$)#i', $relativePath) === 1) {
            throw new InvalidArgumentException('Update archive targets a protected runtime path.');
        }

        $staticMirror = preg_match('#^(assets|brand|demo|preview)/#i', $relativePath) === 1;
        $laravelPublic = preg_match('#^backend/public/#i', $relativePath) === 1;
        $laravelBootstrap = in_array($relativePath, [
            'backend/public/index.php',
            'backend/public/.htaccess',
        ], true);

        if (($staticMirror || ($laravelPublic && ! $laravelBootstrap))
            && (preg_match('#(?:^|/)[^/]*\\.(?:php[0-9]*|phtml|phar|cgi|pl|py|sh|asp|aspx|jsp)(?:\\.|$)#i', $relativePath) === 1
                || preg_match('#(?:^|/)(?:\\.htaccess|\\.user\\.ini|web\\.config)(?:\\.|$)#i', $relativePath) === 1)) {
            throw new InvalidArgumentException('Update archive targets an executable public asset path.');
        }
    }

    /**
     * Do not follow a linked ZIP or a linked parent directory when hashing.
     * A matching caller-supplied checksum does not make a filesystem alias safe.
     */
    private function rejectSymbolicLinkPackagePath(string $packagePath): void
    {
        for ($candidate = $packagePath; ; $candidate = dirname($candidate)) {
            clearstatcache(true, $candidate);

            if (is_link($candidate)) {
                throw new InvalidArgumentException('Update package path cannot be a symbolic link.');
            }

            if (dirname($candidate) === $candidate) {
                break;
            }
        }
    }
}
