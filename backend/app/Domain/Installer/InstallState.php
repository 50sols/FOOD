<?php

namespace App\Domain\Installer;

use JsonException;
use RuntimeException;
use stdClass;

final class InstallState
{
    public function lockPath(): string
    {
        return $this->resolvePath((string) config('foodex.install_lock', 'storage/app/system/installed.lock'));
    }

    public function progressPath(): string
    {
        return $this->resolvePath((string) config('foodex.install_progress', 'storage/app/system/install-progress.json'));
    }

    public function isInstalled(): bool
    {
        $path = $this->lockPath();
        $this->assertSafeStatePath($path);

        if (! is_file($path)) {
            return false;
        }

        // A hardlink can make an unrelated external inode look like the
        // authoritative installed.lock without tripping symlink checks.
        // Reject forged/multiply linked completion markers fail-closed.
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false
            || ($stat['mode'] & 0170000) !== 0100000
            || $stat['nlink'] !== 1) {
            throw new RuntimeException('Installer completion lock is invalid or unsafe.');
        }

        return true;
    }

    /**
     * @return array{completed_step: int, admin: array{name: string, email: string, password_hash: string, locale: string}|null, token: string|null}
     */
    public function progress(): array
    {
        $path = $this->progressPath();
        $this->assertSafeStatePath($path);

        if (! is_file($path)) {
            return ['completed_step' => 0, 'admin' => null, 'token' => null];
        }

        // A hardlink is not a symlink: a multiply linked checkpoint can
        // alias an out-of-tree private inode and supply untrusted installer
        // token/admin credentials. Only a regular single-link state file is
        // a permissible persisted setup checkpoint.
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false
            || ($stat['mode'] & 0170000) !== 0100000
            || $stat['nlink'] !== 1) {
            throw new RuntimeException('Installer progress state is invalid or unsafe.');
        }

        // Existing progress must never be treated as a fresh installation
        // when its contents are corrupt: token() could otherwise overwrite
        // its prior admin, step and token snapshot with a new first-run token.
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Installer progress state could not be read.');
        }

        try {
            $document = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Installer progress state is invalid.');
        }

        // The persisted root is a JSON object, not a scalar or list.
        if (! $document instanceof stdClass) {
            throw new RuntimeException('Installer progress state is invalid.');
        }

        $decoded = (array) $document;
        // A persisted progress object is written with all three keys, even
        // before the admin is configured. Missing fields indicate a partial
        // snapshot; never silently rewrite it as a fresh installation.
        foreach (['completed_step', 'admin', 'token'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $decoded)) {
                throw new RuntimeException('Installer progress state is invalid.');
            }
        }

        $admin = null;
        $candidate = $decoded['admin'];
        if ($candidate !== null) {
            // The writer persists either null or a fully typed admin object.
            // Silently coercing a damaged saved object to null could erase the
            // previously configured administrator on the next checkpoint.
            if (! $candidate instanceof stdClass) {
                throw new RuntimeException('Installer progress state is invalid.');
            }

            $candidate = (array) $candidate;
            if (! isset($candidate['name'], $candidate['email'], $candidate['password_hash'], $candidate['locale'])
                || ! is_string($candidate['name'])
                || ! is_string($candidate['email'])
                || ! is_string($candidate['password_hash'])
                || ! is_string($candidate['locale'])) {
                throw new RuntimeException('Installer progress state is invalid.');
            }

            $admin = [
                'name' => $candidate['name'],
                'email' => $candidate['email'],
                'password_hash' => $candidate['password_hash'],
                'locale' => $candidate['locale'],
            ];
        }

        // The persisted token is null only before initial setup. A damaged
        // non-null token must not be silently reset: token() would replace
        // saved progress and erase the prior setup session's recovery state.
        $token = $decoded['token'];
        if ($token !== null && (! is_string($token) || preg_match('/\\A[a-f0-9]{64}\\z/', $token) !== 1)) {
            throw new RuntimeException('Installer progress state is invalid.');
        }

        // Progress is a persisted trust boundary. A corrupted non-integer or
        // out-of-range step must not silently restart a partially completed
        // installation: that could replay migrations, seeding or key setup
        // and overwrite the authoritative recovery checkpoint.
        $completedStep = $decoded['completed_step'];
        if (! is_int($completedStep) || $completedStep < 0 || $completedStep > 15) {
            throw new RuntimeException('Installer progress state is invalid.');
        }
        // The writer always issues a token together with the first completed
        // step. A missing token later is a damaged checkpoint, not a fresh
        // installation. Do not mint a replacement token over partial setup.
        if ($completedStep > 0 && $token === null) {
            throw new RuntimeException('Installer progress state is invalid.');
        }

        return [
            'completed_step' => $completedStep,
            'admin' => $admin,
            'token' => $token,
        ];
    }

    public function token(): string
    {
        // A completed site cannot mint a new setup session or progress state.
        abort_if($this->isInstalled(), 404);

        $progress = $this->progress();

        if ($progress['token'] !== null) {
            return $progress['token'];
        }

        return $this->withProgressWriteLock(function (): string {
            // A competing checkpoint may have supplied the token while this
            // request waited for the same persistent lock.
            $progress = $this->progress();

            if ($progress['token'] === null) {
                $progress['token'] = bin2hex(random_bytes(32));
                $this->writeJson($this->progressPath(), $progress);
            }

            return $progress['token'];
        });
    }

    /**
     * Progress JSON is replaced by rename, so locking its disposable inode
     * cannot serialize read/modify/write across requests. Both initial token
     * writes and step/admin checkpoints share this persistent, verified lock.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $operation
     * @return TResult
     */
    private function withProgressWriteLock(callable $operation): mixed
    {
        $progressPath = $this->progressPath();
        $tokenLockPath = (string) config(
            'foodex.installer_token_lock_path',
            storage_path('framework/installer-token-locks/'.hash('sha256', $progressPath).'.lock'),
        );
        $this->assertSafeStatePath($tokenLockPath);

        $directory = dirname($tokenLockPath);
        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Installer token lock directory is not writable.');
        }

        $this->assertSafeStatePath($tokenLockPath);
        $lock = @fopen($tokenLockPath, 'c');

        if ($lock === false) {
            throw new RuntimeException('Installer token lock could not be opened.');
        }

        try {
            $this->assertTokenLockFile($tokenLockPath, $lock);
            @chmod($tokenLockPath, 0640);

            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Installer token lock could not be acquired.');
            }

            $this->assertTokenLockFile($tokenLockPath, $lock);

            // Finish can create installed.lock while another request waits
            // for this mutex. Refuse that queued request after obtaining the
            // lock, before it can recreate credentials or setup progress.
            abort_if($this->isInstalled(), 404);

            return $operation();
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  resource  $lock
     */
    private function assertTokenLockFile(string $path, $lock): void
    {
        $this->assertSafeStatePath($path);
        $opened = fstat($lock);
        $linked = @lstat($path);

        // Reject a replaced file, symlink or hardlinked foreign inode before
        // chmod or saving a token. A hardlink passes is_link() and shares the
        // original file's permissions, so the lock must have one link only.
        if ($opened === false || $linked === false
            || ($opened['mode'] & 0170000) !== 0100000
            || ($linked['mode'] & 0170000) !== 0100000
            || $opened['nlink'] !== 1
            || $linked['nlink'] !== 1
            || $opened['dev'] !== $linked['dev']
            || $opened['ino'] !== $linked['ino']) {
            throw new RuntimeException('Installer token lock path changed unexpectedly.');
        }
    }

    /**
     * @param  array{name: string, email: string, password_hash: string, locale: string}|null  $admin
     */
    public function markStepComplete(int $step, ?array $admin = null): void
    {
        // A direct caller must not restore or alter setup state after Finish.
        abort_if($this->isInstalled(), 404);

        // Fail closed before creating a lock file if persisted progress is
        // corrupt, as the original installer state contract requires.
        $this->progress();

        $this->withProgressWriteLock(function () use ($step, $admin): void {
            // A concurrent request may have advanced setup or stored Super
            // Admin details while this request waited. Never write its stale
            // snapshot back over the authoritative progress document.
            $progress = $this->progress();
            $progress['completed_step'] = max($progress['completed_step'], min(15, $step));
            $progress['token'] ??= bin2hex(random_bytes(32));

            if ($admin !== null) {
                $progress['admin'] = $admin;
            }

            $this->writeJson($this->progressPath(), $progress);
        });
    }

    public function writeLock(string $version): void
    {
        // Finalization must serialize with token and checkpoint writes.
        // Otherwise two Finish requests can both pass the outer installed
        // check and replace the authoritative lock through atomic rename.
        abort_if($this->isInstalled(), 404);

        $this->withProgressWriteLock(function () use ($version): void {
            // The mutex checks installed.lock again after acquisition; a
            // queued Finish must never replace the first completed site.
            $this->writeJson($this->lockPath(), [
                'version' => $version,
                'installed_at' => now()->toIso8601String(),
            ]);
        });
    }

    public function clearProgress(): void
    {
        // Only successful finalization may discard the recoverable setup
        // checkpoint. Finish writes installed.lock before this cleanup.
        abort_unless($this->isInstalled(), 404);

        $path = $this->progressPath();
        $this->assertSafeStatePath($path);

        if (is_file($path) && ! @unlink($path)) {
            throw new RuntimeException('Installer progress could not be cleared.');
        }
    }

    /**
     * Installer state must never be read, replaced or removed through a symbolic link.
     * Check existing ancestors too: a normal leaf under a linked directory is unsafe.
     */
    private function assertSafeStatePath(string $path): void
    {
        for ($candidate = $path; ; $candidate = dirname($candidate)) {
            clearstatcache(true, $candidate);

            if (is_link($candidate)) {
                throw new RuntimeException('Installer state path cannot be a symbolic link.');
            }

            if (dirname($candidate) === $candidate) {
                break;
            }
        }
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $this->assertSafeStatePath($path);
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Installer state directory is not writable.');
        }

        $this->assertSafeStatePath($path);
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));

        if (file_put_contents($temporary, $encoded.PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Installer state could not be persisted.');
        }

        @chmod($temporary, 0640);

        try {
            $this->assertSafeStatePath($path);

            if (! @rename($temporary, $path)) {
                throw new RuntimeException('Installer state could not be finalized.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
