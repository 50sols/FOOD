<?php

namespace Tests\Unit;

use App\Domain\Updater\SignedUpdateEnvelope;
use App\Domain\Updater\SignedUpdatePublisher;
use App\Domain\Updater\TrustedUpdateSignatureVerifier;
use App\Domain\Updater\UpdatePackageManifest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SignedUpdatePublisherTest extends TestCase
{
    public function test_emitted_sidecar_verifies_with_the_installer_and_rejects_tampering(): void
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('PHP ext-sodium is required.');
        }

        $pair = sodium_crypto_sign_keypair();
        $verifier = new TrustedUpdateSignatureVerifier;
        $publisher = new SignedUpdatePublisher($verifier);
        $manifest = new UpdatePackageManifest(
            '2.3.4',
            '2.2.0',
            str_repeat('a', 64),
            'Release note — signer/installer parity',
            true,
        );
        $issued = $publisher->issue(
            $manifest,
            'release-key-1',
            base64_encode(sodium_crypto_sign_secretkey($pair)),
        );
        $parsed = SignedUpdateEnvelope::parse($issued);
        $trustedKeys = ['release-key-1' => base64_encode(sodium_crypto_sign_publickey($pair))];
        $parsed->verify($verifier, $trustedKeys);
        $this->assertSame('2.3.4', $parsed->manifest->targetVersion);
        $this->assertTrue($parsed->manifest->containsMigrations);

        $decoded = json_decode($issued, true, 16, JSON_THROW_ON_ERROR);
        foreach ([
            ['target_version', '2.3.5'],
            ['minimum_current_version', '2.1.0'],
            ['archive_sha256', str_repeat('b', 64)],
            ['release_notes', 'Altered release notes'],
            ['contains_migrations', false],
        ] as [$field, $value]) {
            $altered = $decoded;
            $altered[$field] = $value;
            try {
                SignedUpdateEnvelope::parse(json_encode($altered, JSON_THROW_ON_ERROR))
                    ->verify($verifier, $trustedKeys);
                $this->fail('Tampered signed sidecar field was accepted: '.$field);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('signature verification failed', $e->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $parsed->verify($verifier, ['another-key' => $trustedKeys['release-key-1']]);
    }

    public function test_signer_rejects_malformed_private_keys_before_publishing(): void
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('PHP ext-sodium is required.');
        }
        $publisher = new SignedUpdatePublisher(new TrustedUpdateSignatureVerifier);
        $manifest = new UpdatePackageManifest('2.0.0', '1.0.0', str_repeat('a', 64), '', false);

        foreach (['', 'invalid base64', base64_encode('short'), base64_encode(random_bytes(64)).'='] as $bad) {
            try {
                $publisher->issue($manifest, 'owner-key', $bad);
                $this->fail('Malformed signing key was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('signing key', $e->getMessage());
            }
        }

        // Correct length alone is insufficient: a mismatched seed/public
        // portion must not publish an Ed25519 signature that fails installer verification.
        $pair = sodium_crypto_sign_keypair();
        $badKey = sodium_crypto_sign_secretkey($pair);
        $badKey[63] = chr(ord($badKey[63]) ^ 1);
        try {
            $publisher->issue($manifest, 'owner-key', base64_encode($badKey));
            $this->fail('Inconsistent signing key was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('inconsistent', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $publisher->issue($manifest, '../invalid', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
    }
}
