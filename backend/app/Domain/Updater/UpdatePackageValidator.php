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
            if ($archive->numFiles > $maxMembers) {
                throw new InvalidArgumentException('Update archive exceeds the allowed member count.');
            }

            // A valid archive checksum does not make arbitrary ZIP members safe.
            // The verify step must fail before it labels a traversal, symlink or
            // portable-name alias as an installable update. The publisher and
            // runtime preflight retain their independent enforcement.
            $knownPaths = [];
            $filePaths = [];
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $name = $archive->getNameIndex($index);
                if (! is_string($name)) {
                    throw new InvalidArgumentException('Update archive contains an unreadable member name.');
                }

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
                        || preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?\z/iD', $component) === 1) {
                        throw new InvalidArgumentException('Update archive contains an unsafe portable member name.');
                    }
                }

                $foldedPath = strtolower($relativePath);
                if (isset($knownPaths[$foldedPath])) {
                    throw new InvalidArgumentException('Update archive contains ambiguous member aliases.');
                }
                $knownPaths[$foldedPath] = true;
                if (! $isDirectory) {
                    $filePaths[$foldedPath] = true;
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
