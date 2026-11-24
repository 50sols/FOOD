<?php

namespace App\Enterprise\Wms;

use App\Models\User;
use App\Services\OperationalTenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            || ! isset($position->source_inventory_updated_at)
            || $this->legalScopeForStore($storeId) === null
            // A signed warehouse ID is not proof that its current custodian
            // still belongs to this store. Reassignment must revoke title
            // until the exact canonical warehouse/store relationship returns.
            || ! DB::table('warehouses')
                ->where('id', (int) ($inventory->warehouse_id ?? 0))
                ->where('store_id', $storeId)
                ->exists()
            || ! $this->hasProtectedStockMovementHistory((int) $inventory->id)) {
            // Store/warehouse existence alone is not a legal entity identity.
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
        if (! $this->hasIndependentLegalTitleChecker($position, $attestation, $storeId)) {
            return false;
        }

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

        if (! self::hasValidSignedWindow(
            (string) $attestation->signed_at,
            (string) $attestation->expires_at,
        )) {
            return false;
        }

        $keys = config('enterprise_inventory_title.trusted_public_keys', []);
        $publicPem = is_array($keys) ? ($keys[$keyId] ?? null) : null;
        if (! is_string($publicPem) || trim($publicPem) === '') {
            return false;
        }

        // Revocation is keyed by the exact trusted signer ID carried by this
        // attestation. A malformed revocation source must never make a stored
        // signature more trusted than the configured authority intended.
        $revokedKeyIds = config('enterprise_inventory_title.revoked_key_ids', []);
        if (! is_array($revokedKeyIds) || ! array_is_list($revokedKeyIds)) {
            return false;
        }
        foreach ($revokedKeyIds as $revokedKeyId) {
            if (! is_string($revokedKeyId)
                || preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $revokedKeyId) !== 1) {
                return false;
            }
        }
        if (in_array($keyId, $revokedKeyIds, true)) {
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
     * SQL TIMESTAMP legal-title proof must carry two exact UTC instants.
     * Carbon::parse('') silently interprets a blank signing time as "now";
     * relative or normalized calendar text is not an immutable signed instant.
     */
    private static function hasValidSignedWindow(string $signedAtRaw, string $expiresAtRaw): bool
    {
        foreach ([$signedAtRaw, $expiresAtRaw] as $value) {
            if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $value) !== 1) {
                return false;
            }
        }

        try {
            $signedAt = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $signedAtRaw, 'UTC');
            $expiresAt = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $expiresAtRaw, 'UTC');

            return $signedAt !== null && $expiresAt !== null
                && $signedAt->format('Y-m-d H:i:s') === $signedAtRaw
                && $expiresAt->format('Y-m-d H:i:s') === $expiresAtRaw
                && ! $signedAt->isFuture()
                && $expiresAt->isFuture()
                && $expiresAt->greaterThan($signedAt);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A correctly signed document is not permission to impersonate a legal
     * title approver. The checker in the signed payload must be a real active
     * user with this store's independent ownership-review authority, distinct
     * from the proposing operator. Historical authority at signing time still
     * requires the missing immutable approval-event contract.
     */
    private function hasIndependentLegalTitleChecker(object $position, object $attestation, int $storeId): bool
    {
        $checkerId = (int) ($attestation->checked_by ?? 0);
        $proposerId = (int) ($position->proposed_by ?? 0);

        if ($checkerId < 1 || $proposerId < 1 || $checkerId === $proposerId
            || $checkerId !== (int) ($position->certified_by ?? 0)) {
            return false;
        }

        try {
            $checker = User::query()->whereKey($checkerId)->where('is_active', true)->first();
            if ($checker === null || ! $checker->hasPermission('inventory_ownership_rules.review', $storeId)) {
                return false;
            }

            app(OperationalTenantScope::class)->assertStore(
                $checker,
                $storeId,
                'inventory_ownership_rules.review',
            );

            return true;
        } catch (Throwable) {
            // No role, user revoked, inactive store, cross-tenant permission or
            // unavailable RBAC scope must leave title UNVERIFIED.
            return false;
        }
    }

    /**
     * A signed inventory snapshot requires protected movement history, an
     * immutable attestation row, and the immutable owner-event writer. This
     * preserves movement/evidence integrity and captures later movement
     * snapshots without treating those events as legal-title authority.
     * Both attested-movement guards, both evidence guards, the event writer,
     * both event-source guards, both event-row guards, and complete event
     * coverage for this inventory are mandatory.
     *
     * cPanel/shared-hosting users without MySQL TRIGGER/SUPER privileges may
     * install the site, but legal-title certification must fail closed.
     */
    private function hasProtectedStockMovementHistory(?int $inventoryId = null): bool
    {
        try {
            $driver = DB::connection()->getDriverName();

            if ($driver === 'sqlite') {
                $movements = DB::table('sqlite_master')
                    ->where('type', 'trigger')
                    ->where('tbl_name', 'stock_movements')
                    ->whereIn('name', [
                        'p110_attested_movement_no_delete',
                        'p110_attested_movement_no_update',
                    ])
                    ->where('sql', 'like', '%enterprise_inventory_title_attestations%')
                    ->where('sql', 'like', '%RAISE(ABORT%')
                    ->count();

                $attestations = DB::table('sqlite_master')
                    ->where('type', 'trigger')
                    ->where('tbl_name', 'enterprise_inventory_title_attestations')
                    ->whereIn('name', [
                        'p110_attestation_no_delete',
                        'p110_attestation_no_update',
                    ])
                    ->where('sql', 'like', '%RAISE(ABORT%')
                    ->count();

                $eventWriter = DB::table('sqlite_master')
                    ->where('type', 'trigger')
                    ->where('tbl_name', 'stock_movements')
                    ->where('name', 'p110_owner_event_after_movement_insert')
                    ->where('sql', 'like', '%enterprise_inventory_owner_movement_events%')
                    ->where('sql', 'like', '%movement_store_code%')
                    ->count();

                $eventSourceGuards = DB::table('sqlite_master')
                    ->where('type', 'trigger')
                    ->where('tbl_name', 'stock_movements')
                    ->whereIn('name', [
                        'p110_owner_event_movement_no_delete',
                        'p110_owner_event_movement_no_update',
                    ])
                    ->where('sql', 'like', '%enterprise_inventory_owner_movement_events%')
                    ->where('sql', 'like', '%RAISE(ABORT%')
                    ->count();

                $ownerEvents = DB::table('sqlite_master')
                    ->where('type', 'trigger')
                    ->where('tbl_name', 'enterprise_inventory_owner_movement_events')
                    ->whereIn('name', [
                        'p110_owner_event_no_delete',
                        'p110_owner_event_no_update',
                    ])
                    ->where('sql', 'like', '%RAISE(ABORT%')
                    ->count();

                $eventDeleteGuard = DB::table('sqlite_master')
                    ->where('type', 'trigger')
                    ->where('tbl_name', 'stock_movements')
                    ->where('name', 'p110_owner_event_movement_no_delete')
                    ->where('sql', 'like', '%movement_store_code%')
                    ->where('sql', 'like', '%performance_fixture%')
                    ->where('sql', 'like', '%demo_seed%')
                    ->count();

                return $movements === 2 && $attestations === 2
                    && $eventWriter === 1 && $eventSourceGuards === 2 && $eventDeleteGuard === 1 && $ownerEvents === 2
                    && Schema::hasColumn('enterprise_inventory_owner_movement_events', 'movement_store_code')
                    && $this->hasCompleteOwnerMovementEventCoverage($inventoryId);
            }

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $schema = DB::connection()->getDatabaseName();
                $movements = DB::table('information_schema.TRIGGERS')
                    ->where('TRIGGER_SCHEMA', $schema)
                    ->where('EVENT_OBJECT_TABLE', 'stock_movements')
                    ->where('ACTION_TIMING', 'BEFORE')
                    ->whereRaw(
                        '((TRIGGER_NAME = ? AND EVENT_MANIPULATION = ?) OR (TRIGGER_NAME = ? AND EVENT_MANIPULATION = ?))',
                        [
                            'p110_attested_movement_no_delete', 'DELETE',
                            'p110_attested_movement_no_update', 'UPDATE',
                        ],
                    )
                    ->where('ACTION_STATEMENT', 'like', '%enterprise_inventory_title_attestations%')
                    ->where('ACTION_STATEMENT', 'like', '%SIGNAL%')
                    ->count();

                $attestations = DB::table('information_schema.TRIGGERS')
                    ->where('TRIGGER_SCHEMA', $schema)
                    ->where('EVENT_OBJECT_TABLE', 'enterprise_inventory_title_attestations')
                    ->where('ACTION_TIMING', 'BEFORE')
                    ->whereRaw(
                        '((TRIGGER_NAME = ? AND EVENT_MANIPULATION = ?) OR (TRIGGER_NAME = ? AND EVENT_MANIPULATION = ?))',
                        [
                            'p110_attestation_no_delete', 'DELETE',
                            'p110_attestation_no_update', 'UPDATE',
                        ],
                    )
                    ->where('ACTION_STATEMENT', 'like', '%SIGNAL%')
                    ->count();

                $eventWriter = DB::table('information_schema.TRIGGERS')
                    ->where('TRIGGER_SCHEMA', $schema)
                    ->where('EVENT_OBJECT_TABLE', 'stock_movements')
                    ->where('TRIGGER_NAME', 'p110_owner_event_after_movement_insert')
                    ->where('ACTION_TIMING', 'AFTER')
                    ->where('EVENT_MANIPULATION', 'INSERT')
                    ->where('ACTION_STATEMENT', 'like', '%enterprise_inventory_owner_movement_events%')
                    ->where('ACTION_STATEMENT', 'like', '%movement_store_code%')
                    ->count();

                $eventSourceGuards = DB::table('information_schema.TRIGGERS')
                    ->where('TRIGGER_SCHEMA', $schema)
                    ->where('EVENT_OBJECT_TABLE', 'stock_movements')
                    ->where('ACTION_TIMING', 'BEFORE')
                    ->whereRaw(
                        '((TRIGGER_NAME = ? AND EVENT_MANIPULATION = ?) OR (TRIGGER_NAME = ? AND EVENT_MANIPULATION = ?))',
                        [
                            'p110_owner_event_movement_no_delete', 'DELETE',
                            'p110_owner_event_movement_no_update', 'UPDATE',
                        ],
                    )
                    ->where('ACTION_STATEMENT', 'like', '%enterprise_inventory_owner_movement_events%')
                    ->where('ACTION_STATEMENT', 'like', '%SIGNAL%')
                    ->count();

                $ownerEvents = DB::table('information_schema.TRIGGERS')
                    ->where('TRIGGER_SCHEMA', $schema)
                    ->where('EVENT_OBJECT_TABLE', 'enterprise_inventory_owner_movement_events')
                    ->where('ACTION_TIMING', 'BEFORE')
                    ->whereRaw(
                        '((TRIGGER_NAME = ? AND EVENT_MANIPULATION = ?) OR (TRIGGER_NAME = ? AND EVENT_MANIPULATION = ?))',
                        [
                            'p110_owner_event_no_delete', 'DELETE',
                            'p110_owner_event_no_update', 'UPDATE',
                        ],
                    )
                    ->where('ACTION_STATEMENT', 'like', '%SIGNAL%')
                    ->count();

                $eventDeleteGuard = DB::table('information_schema.TRIGGERS')
                    ->where('TRIGGER_SCHEMA', $schema)
                    ->where('EVENT_OBJECT_TABLE', 'stock_movements')
                    ->where('TRIGGER_NAME', 'p110_owner_event_movement_no_delete')
                    ->where('ACTION_TIMING', 'BEFORE')
                    ->where('EVENT_MANIPULATION', 'DELETE')
                    ->where('ACTION_STATEMENT', 'like', '%movement_store_code%')
                    ->where('ACTION_STATEMENT', 'like', '%performance_fixture%')
                    ->where('ACTION_STATEMENT', 'like', '%demo_seed%')
                    ->count();

                // Trigger-level atomicity depends on transactional source and
                // snapshot tables. A non-InnoDB writer would otherwise be
                // able to commit a movement without its event on rollback.
                $transactionalTables = DB::table('information_schema.TABLES')
                    ->where('TABLE_SCHEMA', $schema)
                    ->whereIn('TABLE_NAME', [
                        'stock_movements',
                        'inventories',
                        'enterprise_inventory_owner_positions',
                        'enterprise_inventory_title_attestations',
                        'enterprise_inventory_owner_movement_events',
                    ])
                    ->where('ENGINE', 'InnoDB')
                    ->count();

                return $movements === 2 && $attestations === 2
                    && $eventWriter === 1 && $eventSourceGuards === 2 && $eventDeleteGuard === 1 && $ownerEvents === 2
                    && Schema::hasColumn('enterprise_inventory_owner_movement_events', 'movement_store_code')
                    && $transactionalTables === 5
                    && $this->hasCompleteOwnerMovementEventCoverage($inventoryId);
            }
        } catch (Throwable) {
            // Missing SQL metadata privileges, incomplete DDL or unsupported
            // drivers must never certify an owner's legal title.
            return false;
        }

        return false;
    }

    /**
     * Existing non-demo movements without event-time snapshots cannot be
     * reconstructed safely, so their inventory remains uncertified.
     */
    private function hasCompleteOwnerMovementEventCoverage(?int $inventoryId): bool
    {
        $unrecorded = DB::table('stock_movements as movement')
            ->leftJoin(
                'enterprise_inventory_owner_movement_events as owner_event',
                'owner_event.source_stock_movement_id',
                '=',
                'movement.id',
            )
            ->where(function ($query): void {
                // An event with a matching source ID but altered source
                // attributes is not proof of an immutable movement history.
                // Compare all recorded source fields, including both NULL
                // directions for nullable columns, on MySQL and SQLite.
                $query->whereNull('owner_event.source_stock_movement_id')
                    ->orWhereColumn('owner_event.inventory_id', '<>', 'movement.inventory_id')
                    ->orWhereColumn('owner_event.movement_type', '<>', 'movement.type')
                    ->orWhereColumn('owner_event.movement_quantity', '<>', 'movement.quantity');

                foreach ([
                    'movement_store_id' => 'store_id',
                    'movement_user_id' => 'user_id',
                    'movement_reference_type' => 'reference_type',
                    'movement_reference_id' => 'reference_id',
                    'movement_created_at' => 'created_at',
                ] as $snapshotColumn => $sourceColumn) {
                    $snapshot = 'owner_event.'.$snapshotColumn;
                    $source = 'movement.'.$sourceColumn;

                    $query->orWhere(function ($difference) use ($snapshot, $source): void {
                        $difference->whereColumn($snapshot, '<>', $source)
                            ->orWhere(function ($nullMismatch) use ($snapshot, $source): void {
                                $nullMismatch->whereNull($snapshot)->whereNotNull($source);
                            })
                            ->orWhere(function ($nullMismatch) use ($snapshot, $source): void {
                                $nullMismatch->whereNotNull($snapshot)->whereNull($source);
                            });
                    });
                }
            });

        if ($inventoryId === null) {
            // The unscoped guard validates live sources only: approved demo
            // cleanup preserves historical immutable events after deleting
            // disposable movement rows.
            return ! $unrecorded->exists();
        }

        $unrecorded->where('movement.inventory_id', $inventoryId);
        if ($unrecorded->exists()) {
            return false;
        }

        // Conversely, a privileged DELETE bypass can erase a canonical
        // movement while leaving its event. A scoped inventory must never
        // regain legal-title certification merely because its source row no
        // longer participates in the LEFT JOIN above.
        return ! DB::table('enterprise_inventory_owner_movement_events as owner_event')
            ->where('owner_event.inventory_id', $inventoryId)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('stock_movements as movement')
                    ->whereColumn('movement.id', 'owner_event.source_stock_movement_id')
                    ->whereColumn('movement.inventory_id', 'owner_event.inventory_id');
            })
            ->exists();
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
        $legalScope = $this->legalScopeForStore($storeId);

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
            'legal_entity_id' => $legalScope === null ? null : (int) $legalScope->legal_entity_id,
            'company_id' => $legalScope === null ? null : (int) $legalScope->company_id,
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

    /**
     * Legal title belongs to an enterprise legal entity (or independently
     * identified supplier), not a tenant store or warehouse custodian. An
     * attestation must become invalid when an otherwise identical store is
     * reassigned to a different legal entity.
     */
    private function legalScopeForStore(int $storeId): ?object
    {
        // Signed legal-title proof must not remain usable after any
        // ancestor in the store's legal hierarchy is deactivated. Resolve
        // the entire effective chain, never just a historical scope row.
        return DB::table('enterprise_store_scopes as scope')
            ->join('stores as store', 'store.id', '=', 'scope.store_id')
            ->join('enterprise_business_units as unit', 'unit.id', '=', 'scope.business_unit_id')
            ->join('enterprise_legal_entities as entity', 'entity.id', '=', 'unit.legal_entity_id')
            ->join('enterprise_companies as company', 'company.id', '=', 'entity.company_id')
            ->join('enterprise_organizations as organization', 'organization.id', '=', 'company.organization_id')
            ->where('scope.store_id', $storeId)
            ->where('store.is_active', true)
            ->where('unit.is_active', true)
            ->where('entity.is_active', true)
            ->where('company.is_active', true)
            ->where('organization.is_active', true)
            ->first(['entity.id as legal_entity_id', 'entity.company_id']);
    }
}
