<?php

namespace App\Domain\Updater;

use InvalidArgumentException;
use RuntimeException;

/**
 * Build-side Ed25519 sidecar issuer. The private key must come from the
 * protected CI environment, never a release ZIP or repository configuration.
 */
final class SignedUpdatePublisher
{
    public function __construct(
        private readonly TrustedUpdateSignatureVerifier $verifier,
    ) {}

    public function issue(UpdatePackageManifest $manifest, string $keyId, string $privateKeyBase64): string
    {
        if (! function_exists('sodium_crypto_sign_detached')) {
            throw new RuntimeException('Ed25519 release signing is unavailable on this PHP host.');
        }

        // This also rejects noncanonical versions, SHA-256 and key IDs,
        // ensuring that the signer and installer sign identical bytes.
        $payload = $this->verifier->canonicalPayload($manifest, $keyId);
        $privateKey = base64_decode($privateKeyBase64, true);
        if (! is_string($privateKey)
            || strlen($privateKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || ! hash_equals(base64_encode($privateKey), $privateKeyBase64)) {
            throw new InvalidArgumentException('Protected release signing key is invalid.');
        }

        try {
            $signature = sodium_crypto_sign_detached($payload, $privateKey);
        } finally {
            sodium_memzero($privateKey);
        }

        $sidecar = [
            'format' => 'foodex-signed-update-v1',
            'signer_key_id' => $keyId,
            'target_version' => $manifest->targetVersion,
            'minimum_current_version' => $manifest->minimumCurrentVersion,
            'archive_sha256' => $manifest->sha256,
            'contains_migrations' => $manifest->containsMigrations,
            'release_notes' => $manifest->releaseNotes,
            'signature' => base64_encode($signature),
        ];

        return json_encode($sidecar, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
