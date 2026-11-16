<?php

namespace App\Enterprise\Wms;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Verifies an independently signed legal-title assertion against the exact
 * current inventory owner partition and an actual PRIVATE source document.
 *
 * Configuration carries APPROVED public keys only; the certificate never
 * chooses its own trust anchor. No signing private keys live in the app.
 */
final class InventoryLegalTitleAttestationVerifier
{
    /** @param object $inventory @param object $position */
    public function stockRevisionSha256(object $inventory, object $position, int $storeId): string
    {
        return hash('sha256', $this->stockIdentity($inventory, $position, $storeId));
    }

    /**
     * Canonical data signed by the independent legal-title authority.
     */
    public function signedPayload(object $inventory, object $position, int $storeId, object $attestation): string
    {
        return json_encode([
            'contract' => 'foodex.inventory-legal-title.v1',
            'stock_identity' => json_decode($this->stockIdentity($inventory, $position, $storeId), true, 512, JSON_THROW_ON_ERROR),
            'document_kind' => (string) $attestation->document_kind,
            'document_path' => (string) $attestation->document_path,
            'document_sha256' => (string) $attestation->document_sha256,
            'trusted_key_id' => (string) $attestation->trusted_key_id,
            'checked_by' => (int) $attestation->checked_by,
            'signed_at' => (string) $attestation->signed_at,
            'expires_at' => (string) $attestation->expires_at,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param object $inventory @param object $position */
    public function verifies(object $inventory, object $position, int $storeId): bool
    {
        if (! isset($position->id) || ! isset($position->certified_by)
            || ! isset($position->source_inventory_updated_at)) {
            return false;
        }

        try {
            $stockSha = $this->stockRevisionSha256($inventory, $position, $storeId);
        } catch (Throwable) {
            // Corrupt or out-of-range canonical owner quantities must fail
            // closed rather than become usable legal-title evidence.
            return false;
        }
        $attestations = DB::table('enterprise_inventory_title_attestations')
            ->where('owner_position_id', (int) $position->id)
            ->where('stock_revision_sha256', $stockSha)
            ->limit(2)
            ->get();

        if ($attestations->count() !== 1) {
            return false;
        }

        $attestation = $attestations->first();
        $keyId = (string) $attestation->trusted_key_id;
        $digest = (string) $attestation->document_sha256;
        $path = (string) $attestation->document_path;

        if ((int) $attestation->checked_by !== (int) $position->certified_by
            || preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $keyId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || preg_match('/\Ainventory-title-evidence\/[a-f0-9-]{36}\.(?:pdf|png|jpg)\z/D', $path) !== 1
            || ! in_array((string) $attestation->document_kind, [
                'SIGNED_CONTRACT', 'CONSIGNMENT_AGREEMENT',
                'TITLE_ADDENDUM', 'TRANSFER_CONFIRMATION',
            ], true)) {
            return false;
        }

        try {
            $signedAt = CarbonImmutable::parse((string) $attestation->signed_at, 'UTC');
            $expiry = CarbonImmutable::parse((string) $attestation->expires_at, 'UTC');
            if ($signedAt->isFuture() || $expiry->isPast() || $expiry->equalTo($signedAt)) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $keys = config('enterprise_inventory_title.trusted_public_keys', []);
        $publicPem = is_array($keys) ? ($keys[$keyId] ?? null) : null;
        if (! is_string($publicPem) || trim($publicPem) === '') {
            return false;
        }

        // Reject traversal and symlink aliases, including the private document
        // directory itself. A digest of user-provided text is not document proof.
        $disk = Storage::disk('local');
        $diskRoot = realpath($disk->path(''));
        $privateDirectory = $disk->path('inventory-title-evidence');
        $file = $disk->path($path);
        $realFile = realpath($file);
        if ($diskRoot === false || is_link($privateDirectory) || is_link($file)
            || $realFile === false || ! is_file($realFile)
            || ! str_starts_with($realFile, $diskRoot.DIRECTORY_SEPARATOR)
            || ! is_readable($realFile)) {
            return false;
        }

        $fileSize = filesize($realFile);
        if (! is_int($fileSize) || $fileSize < 1 || $fileSize > 25 * 1024 * 1024) {
            return false;
        }

        $actualDigest = hash_file('sha256', $realFile);
        if (! is_string($actualDigest) || ! hash_equals($digest, $actualDigest)) {
            return false;
        }

        $signature = base64_decode((string) $attestation->signature_base64, true);
        if (! is_string($signature) || $signature === '' || strlen($signature) > 4096) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($publicPem);
        if ($publicKey === false) {
            return false;
        }

        return openssl_verify(
            $this->signedPayload($inventory, $position, $storeId, $attestation),
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA256,
        ) === 1;
    }

    /**
     * DECIMAL(14,3) quantities must never be converted through binary float.
     * At 11 integer digits, distinct milli-units are not always representable
     * as distinct PHP float values; the certificate digest must distinguish
     * every legal source quantity exactly.
     */
    private static function exactQuantity(mixed $value): string
    {
        $raw = trim((string) $value);
        if (preg_match('/\A([0-9]{1,11})(?:\.([0-9]{1,3}))?\z/D', $raw, $matches) !== 1) {
            throw new \UnexpectedValueException('Noncanonical signed owner quantity.');
        }

        $integer = ltrim($matches[1], '0');

        return ($integer === '' ? '0' : $integer).'.'.str_pad($matches[2] ?? '', 3, '0');
    }

    /** @param object $inventory @param object $position */
    private function stockIdentity(object $inventory, object $position, int $storeId): string
    {
        // Canonical balances can change and return to the same quantity within
        // a timestamp's SQL precision. A later stock movement must invalidate
        // the old title proof even when on-hand/reserved and updated_at match.
        // This is a detection watermark, not an immutable owner-event ledger.
        $movementVersion = DB::table('stock_movements')
            ->where('inventory_id', (int) $inventory->id)
            ->selectRaw('COUNT(*) as movement_count, MAX(id) as last_movement_id')
            ->first();

        return json_encode([
            'inventory_id' => (int) $inventory->id,
            'owner_position_id' => (int) $position->id,
            'store_id' => $storeId,
            'warehouse_id' => (int) $inventory->warehouse_id,
            'product_id' => (int) $inventory->product_id,
            'stock_movement_count' => (int) ($movementVersion->movement_count ?? 0),
            'last_stock_movement_id' => $movementVersion->last_movement_id === null
                ? null : (int) $movementVersion->last_movement_id,
            'owner_key' => (string) $position->owner_key,
            'owner_kind' => (string) $position->owner_kind,
            'supplier_id' => $position->supplier_id === null ? null : (int) $position->supplier_id,
            'quantity' => self::exactQuantity($position->quantity),
            'reserved_quantity' => self::exactQuantity($position->reserved_quantity),
            'source_inventory_updated_at' => (string) $position->source_inventory_updated_at,
            'evidence_type' => (string) $position->evidence_type,
            'evidence_reference' => (string) $position->evidence_reference,
            'proposed_by' => (int) $position->proposed_by,
            'certified_by' => (int) $position->certified_by,
            'certified_at' => (string) $position->certified_at,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
