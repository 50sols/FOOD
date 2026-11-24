<?php

declare(strict_types=1);

/**
 * Build-only Setup signature sidecar filesystem identity guards.
 * No signer key, ZIP bytes or runtime update privileges are available here.
 */
function setupSignatureMatchesExclusiveFile(string $path, mixed $openedStat): bool
{
    if (! is_array($openedStat) || ! isset($openedStat['dev'], $openedStat['ino'], $openedStat['mode'])
        || ($openedStat['mode'] & 0170000) !== 0100000) {
        return false;
    }

    // A caller may replace either the output or an ancestor after the first
    // preflight. A symlinked alias must not count as the published sidecar.
    clearstatcache(true);
    for ($candidate = $path; ; $candidate = dirname($candidate)) {
        if (is_link($candidate)) {
            return false;
        }
        if ($candidate === dirname($candidate)) {
            break;
        }
    }

    $onDisk = @lstat($path);

    return is_array($onDisk)
        && ($onDisk['mode'] & 0170000) === 0100000
        && $onDisk['dev'] === $openedStat['dev']
        && $onDisk['ino'] === $openedStat['ino'];
}

/**
 * Cleanup may remove only the exact newly created inode, never a replacement.
 * Exclusive fopen('x') separately guarantees an existing artifact is not
 * overwritten before the publisher opens the file.
 */
function setupSignatureRemoveOwnedIncompleteFile(string $path, mixed $openedStat): void
{
    if (setupSignatureMatchesExclusiveFile($path, $openedStat)) {
        @unlink($path);
    }
}
