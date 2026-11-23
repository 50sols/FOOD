#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__.'/v2_setup_sidecar_fs.php';

/**
 * Build-host Setup ZIP detached Ed25519 sidecar signer and trust-root verifier.
 * No signing secrets are read from command-line arguments or release packages.
 * The sign mode independently runs the canonical Python archive verifier.
 * Signing is a prerequisite, not an authorization to publish or deploy.
 */
function refuse(): never
{
    fwrite(STDERR, "Setup signature inputs could not be authenticated.\n");
    exit(1);
}

function requiredOption(array $args, string $name): string
{
    $value = $args[$name] ?? null;
    if (! is_string($value) || $value === '') {
        throw new RuntimeException('Missing required argument.');
    }

    return $value;
}

function rejectLinkedPath(string $path): void
{
    // PHP stream wrappers (e.g. php://temp) can accept exclusive 'x' opens
    // without creating a real release sidecar on disk.
    if (str_contains($path, '://')) {
        throw new RuntimeException('Release path must be a filesystem path.');
    }
    for ($candidate = $path; ; $candidate = dirname($candidate)) {
        if (is_link($candidate)) {
            throw new RuntimeException('Symlinked release path.');
        }
        if ($candidate === dirname($candidate)) {
            break;
        }
    }
}

function canonicalBase64(string $value, int $length): string
{
    $bytes = base64_decode($value, true);
    if (! is_string($bytes) || strlen($bytes) !== $length ||
        ! hash_equals(base64_encode($bytes), $value)) {
        throw new RuntimeException('Invalid signing material.');
    }

    return $bytes;
}

function canonicalPayload(string $keyId, string $version, string $sha, string $commit): string
{
    if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,79}\z/D', $keyId) !== 1 ||
        preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $version) !== 1 ||
        preg_match('/\A[a-f0-9]{64}\z/D', $sha) !== 1 ||
        preg_match('/\A[a-f0-9]{40}\z/D', $commit) !== 1) {
        throw new RuntimeException('Noncanonical release provenance.');
    }
    $payload = [
        'format' => 'foodex-setup-manifest-v1',
        'signer_key_id' => $keyId,
        'version' => $version,
        'source_commit' => $commit,
        'archive_sha256' => $sha,
    ];

    return "FOODEX-SETUP-SIGNATURE-V1\n".json_encode(
        $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}

function verifiedStaging(string $package, string $versionFile, string $commit): array
{
    $python = getenv('FOODEX_SETUP_VERIFIER_PYTHON');
    $python = is_string($python) && $python !== '' ? $python : 'python3';
    $script = __DIR__.'/v2_setup_staging_verify.py';
    $evidence = tempnam(sys_get_temp_dir(), 'foodex-setup-proof-');
    if (! is_string($evidence)) {
        throw new RuntimeException('Cannot allocate verification evidence.');
    }
    try {
        $command = [
            $python, $script, '--zip', $package, '--version-file', $versionFile,
            '--source-commit', $commit, '--evidence', $evidence,
        ];
        // The build-time signer holds a protected Ed25519 secret in its own
        // environment. The independent archive validator only needs public
        // inputs; never expose signing material to this child process.
        $verifierEnvironment = getenv();
        if (! is_array($verifierEnvironment)) {
            throw new RuntimeException('Cannot isolate signing key from verifier.');
        }
        unset($verifierEnvironment['FOODEX_SETUP_SIGNING_SECRET_KEY_B64']);
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            __DIR__,
            $verifierEnvironment,
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Setup verifier unavailable.');
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Staged Setup archive failed independent validation.');
        }
        $proof = json_decode((string) file_get_contents($evidence), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($proof) ||
            ($proof['status'] ?? null) !== 'UNTRUSTED_CI_STAGING_ONLY' ||
            ($proof['signed'] ?? null) !== false ||
            ($proof['source_commit'] ?? null) !== $commit ||
            ($proof['sha256'] ?? null) !== hash_file('sha256', $package) ||
            ($proof['version'] ?? null) !== trim((string) file_get_contents($versionFile))) {
            throw new RuntimeException('Setup verifier provenance mismatch.');
        }

        return $proof;
    } finally {
        @unlink($evidence);
    }
}

try {
    $args = getopt('', ['mode:', 'package:', 'version-file:', 'source-commit:', 'output:', 'sidecar:']);
    $mode = requiredOption($args, 'mode');
    $package = requiredOption($args, 'package');
    if (! in_array($mode, ['sign', 'verify'], true) ||
        ! is_file($package) || ! is_readable($package)) {
        throw new RuntimeException('Invalid Setup archive or mode.');
    }
    rejectLinkedPath($package);
    if (! function_exists('sodium_crypto_sign_detached')) {
        throw new RuntimeException('Sodium unavailable.');
    }

    if ($mode === 'sign') {
        $versionFile = requiredOption($args, 'version-file');
        $commit = requiredOption($args, 'source-commit');
        $output = requiredOption($args, 'output');
        rejectLinkedPath($versionFile);
        rejectLinkedPath($output);
        if (file_exists($output) || is_link($output) ||
            ! is_file($versionFile) || ! is_readable($versionFile)) {
            throw new RuntimeException('Invalid signing input or existing output.');
        }
        $keyId = getenv('FOODEX_SETUP_SIGNING_KEY_ID');
        $secretEncoded = getenv('FOODEX_SETUP_SIGNING_SECRET_KEY_B64');
        if (! is_string($keyId) || ! is_string($secretEncoded)) {
            throw new RuntimeException('Signing credentials unavailable.');
        }
        $proof = verifiedStaging($package, $versionFile, $commit);
        $payload = canonicalPayload($keyId, $proof['version'], $proof['sha256'], $commit);
        $secret = canonicalBase64($secretEncoded, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        try {
            $sig = sodium_crypto_sign_detached($payload, $secret);
            $public = sodium_crypto_sign_publickey_from_secretkey($secret);
            if (! sodium_crypto_sign_verify_detached($sig, $payload, $public)) {
                throw new RuntimeException('Inconsistent release signing key.');
            }
        } finally {
            sodium_memzero($secret);
        }
        $sidecar = json_encode([
            'format' => 'foodex-signed-setup-v1',
            'signer_key_id' => $keyId,
            'version' => $proof['version'],
            'source_commit' => $commit,
            'archive_sha256' => $proof['sha256'],
            'signature' => base64_encode($sig),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stream = fopen($output, 'x');
        if ($stream === false) {
            throw new RuntimeException('Cannot exclusively publish signature.');
        }
        $openedStat = fstat($stream);
        $written = false;
        $closed = false;
        $sameOutput = false;
        try {
            // An exclusive open is not sufficient: a stream wrapper, FIFO,
            // or replaced output path must never certify a published Setup ZIP.
            if (! is_array($openedStat) || ($openedStat['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException('Setup signature output must be a regular file.');
            }
            $length = strlen($sidecar);
            $offset = 0;
            while ($offset < $length) {
                $bytes = fwrite($stream, substr($sidecar, $offset));
                if (! is_int($bytes) || $bytes <= 0) {
                    throw new RuntimeException('Incomplete signed sidecar.');
                }
                $offset += $bytes;
            }
            if (! fflush($stream)) {
                throw new RuntimeException('Incomplete signed sidecar.');
            }
            $written = true;
        } finally {
            $closed = fclose($stream);
            // Verify the final pathname still refers to the very inode this
            // invocation created. Never unlink another actor's replacement.
            $sameOutput = setupSignatureMatchesExclusiveFile($output, $openedStat);
            if ((! $written || ! $closed) && $sameOutput) {
                setupSignatureRemoveOwnedIncompleteFile($output, $openedStat);
            }
        }
        if (! $written || ! $closed || ! $sameOutput) {
            throw new RuntimeException('Setup signature output could not be safely finalized.');
        }
        fwrite(STDOUT, "Setup sidecar signed; release acceptance is still required.\n");
    } else {
        $sidecarPath = requiredOption($args, 'sidecar');
        rejectLinkedPath($sidecarPath);
        if (! is_file($sidecarPath) || filesize($sidecarPath) > 8192) {
            throw new RuntimeException('Signed Setup sidecar is invalid.');
        }
        $sidecarBytes = file_get_contents($sidecarPath);
        if (! is_string($sidecarBytes)) {
            throw new RuntimeException('Unreadable signed Setup sidecar.');
        }
        $sidecar = json_decode($sidecarBytes, true, 16, JSON_THROW_ON_ERROR);
        // Reject duplicate JSON keys and alternate encodings. The signer
        // writes this exact compact, ordered representation; accepting a
        // different byte stream could let another consumer interpret the
        // supposedly authenticated manifest differently.
        if (! is_array($sidecar)) {
            throw new RuntimeException('Invalid signed Setup sidecar.');
        }
        if (json_encode(
            $sidecar,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) !== $sidecarBytes) {
            throw new RuntimeException('Noncanonical signed Setup sidecar.');
        }
        if (
            array_keys($sidecar) !== [
                'format', 'signer_key_id', 'version',
                'source_commit', 'archive_sha256', 'signature',
            ] || $sidecar['format'] !== 'foodex-signed-setup-v1' ||
            ! is_string($sidecar['signature']) ||
            ($sidecar['archive_sha256'] ?? null) !== hash_file('sha256', $package)) {
            throw new RuntimeException('Setup archive provenance mismatch.');
        }
        $payload = canonicalPayload(
            $sidecar['signer_key_id'], $sidecar['version'],
            $sidecar['archive_sha256'], $sidecar['source_commit'],
        );
        $keyring = getenv('FOODEX_SETUP_TRUSTED_PUBLIC_KEYS_JSON');
        $trusted = is_string($keyring) ? json_decode($keyring, true, 32, JSON_THROW_ON_ERROR) : null;
        if (! is_array($trusted) ||
            ! is_string($trusted[$sidecar['signer_key_id']] ?? null)) {
            throw new RuntimeException('Untrusted Setup publisher.');
        }
        $public = canonicalBase64($trusted[$sidecar['signer_key_id']], SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $signature = canonicalBase64($sidecar['signature'], SODIUM_CRYPTO_SIGN_BYTES);
        if (! sodium_crypto_sign_verify_detached($signature, $payload, $public)) {
            throw new RuntimeException('Setup publisher signature failed.');
        }

        // A valid signature on a sidecar is not enough if its advertised
        // version conflicts with the installer's own VERSION file. Verify
        // the signed version against the exact archive being distributed.
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZIP extension unavailable for Setup verification.');
        }
        $archive = new ZipArchive();
        if ($archive->open($package, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Signed Setup ZIP is unreadable.');
        }
        try {
            $versionEntry = $archive->statName('VERSION');
            if (! is_array($versionEntry) ||
                ($versionEntry['size'] ?? PHP_INT_MAX) > 128) {
                throw new RuntimeException('Signed Setup ZIP VERSION is missing or invalid.');
            }
            $embeddedVersion = $archive->getFromName('VERSION');
            if (! is_string($embeddedVersion) ||
                trim($embeddedVersion) !== $sidecar['version']) {
                throw new RuntimeException('Signed Setup ZIP version mismatch.');
            }
        } finally {
            $archive->close();
        }
        fwrite(STDOUT, "Verified Setup archive signature and SHA-256.\n");
    }
} catch (Throwable) {
    refuse();
}
