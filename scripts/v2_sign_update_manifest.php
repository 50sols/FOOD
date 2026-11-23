#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build-host only, not a web endpoint. Sign a staged update ZIP using a
 * protected Ed25519 secret held in environment, never in Git or in the ZIP.
 */
require __DIR__.'/../backend/vendor/autoload.php';

use App\Domain\Updater\SignedUpdatePublisher;
use App\Domain\Updater\TrustedUpdateSignatureVerifier;
use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdatePackageValidator;
use App\Domain\Updater\SignedUpdateEnvelope;

try {
    $options = getopt('', [
        'package:', 'target-version:', 'minimum-current-version:',
        'release-notes-file:', 'contains-migrations:', 'output:',
    ]);
    foreach (['package', 'target-version', 'minimum-current-version', 'release-notes-file', 'contains-migrations', 'output'] as $key) {
        if (! isset($options[$key]) || ! is_string($options[$key]) || $options[$key] === '') {
            throw new RuntimeException('Required update publisher argument is missing.');
        }
    }
    if (! in_array($options['contains-migrations'], ['0', '1'], true)) {
        throw new RuntimeException('Migration declaration must be 0 or 1.');
    }
    $keyId = getenv('FOODEX_UPDATE_SIGNING_KEY_ID');
    $secret = getenv('FOODEX_UPDATE_SIGNING_SECRET_KEY_B64');
    if (! is_string($keyId) || $keyId === '' || ! is_string($secret) || $secret === '') {
        throw new RuntimeException('Protected publisher credentials are unavailable.');
    }

    $zipPath = $options['package'];
    $notesPath = $options['release-notes-file'];
    $outputPath = $options['output'];
    if (! is_file($zipPath) || ! is_readable($zipPath)
        || ! is_file($notesPath) || ! is_readable($notesPath)) {
        throw new RuntimeException('Package or release notes are unavailable.');
    }
    foreach ([$zipPath, $notesPath, $outputPath] as $path) {
        for ($candidate = $path; ; $candidate = dirname($candidate)) {
            if (is_link($candidate)) {
                throw new RuntimeException('Publisher paths may not use symlinks.');
            }
            if (dirname($candidate) === $candidate) {
                break;
            }
        }
    }
    if (file_exists($outputPath) || is_link($outputPath)) {
        throw new RuntimeException('Publisher refuses to replace an existing sidecar.');
    }

    $sha = hash_file('sha256', $zipPath);
    $notes = file_get_contents($notesPath);
    if (! is_string($sha) || ! is_string($notes) || strlen($notes) > 5000) {
        throw new RuntimeException('Release inputs cannot be read or exceed limits.');
    }

    $manifest = new UpdatePackageManifest(
        $options['target-version'], $options['minimum-current-version'],
        $sha, $notes, $options['contains-migrations'] === '1',
    );
    // Never issue a trusted-looking signature for corrupt/non-ZIP bytes or a
    // false migration declaration. This is the same domain validator used
    // during installation, and must pass before any sidecar is created.
    (new UpdatePackageValidator)->validate(
        $manifest, $manifest->minimumCurrentVersion, $zipPath,
    );
    $verifier = new TrustedUpdateSignatureVerifier;
    $json = (new SignedUpdatePublisher($verifier))->issue($manifest, $keyId, $secret);
    // Validate the final signed envelope format before publication.
    SignedUpdateEnvelope::parse($json);
    $output = fopen($outputPath, 'x');
    if ($output === false) {
        throw new RuntimeException('Publisher cannot create an exclusive sidecar output.');
    }
    try {
        if (fwrite($output, $json) !== strlen($json) || ! fflush($output)) {
            throw new RuntimeException('Publisher could not write signed sidecar.');
        }
    } finally {
        fclose($output);
    }
    fwrite(STDOUT, "Signed sidecar issued; distribute only after release acceptance.\n");
} catch (Throwable $exception) {
    // Never print arguments, secret values, or internal exception detail.
    fwrite(STDERR, "Signed update publisher refused the release inputs.\n");
    exit(1);
}
