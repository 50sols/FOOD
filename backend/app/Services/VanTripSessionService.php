<?php

namespace App\Services;

use App\Models\User;
use App\Models\Van;
use App\Models\VanAssignment;
use App\Models\VanTripSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Isolated, fail-closed P276 trip identity writer.
 *
 * This is not a Van stock source, a field-trip HTTP endpoint, or a settlement
 * authorizer. Until an immutable custody/finance closeout contract exists,
 * a Van's trip slot cannot be released after ending the operational trip.
 */
final class VanTripSessionService
{
    public function __construct(private readonly OperationalTenantScope $tenantScope) {}

    public function start(User $actor, int $assignmentId, string $key): VanTripSession
    {
        $this->assertValidKey($key);
        abort_unless($actor->is_active, 403);

        return DB::transaction(function () use ($actor, $assignmentId, $key): VanTripSession {
            $assignment = VanAssignment::query()->lockForUpdate()->findOrFail($assignmentId);

            // Offline retries must resolve the immutable original outcome even
            // after a Van is suspended or its assignment expires. This is a
            // replay, not renewed permission to dispatch a new trip: require
            // the same initiating actor and live store-scoped permissions.
            $replayed = VanTripSession::query()
                ->where('van_assignment_id', $assignment->id)
                ->where('start_key', $key)
                ->first();
            if ($replayed !== null) {
                abort_unless((int) $replayed->started_by_user_id === (int) $actor->id, 409);
                $this->tenantScope->assertStore($actor, (int) $replayed->store_id, 'drivers.b2b.manage', 'b2b');
                $this->tenantScope->assertStore($actor, (int) $replayed->store_id, 'customers.view', 'b2b');
                $this->assertImmutableTripStartEvidence($replayed);

                return $replayed;
            }

            $van = Van::query()->whereKey($assignment->van_id)->lockForUpdate()->firstOrFail();
            abort_unless($assignment->status === 'active' && $van->status === 'active', 404);
            $effectiveFrom = $assignment->effective_from;
            $effectiveUntil = $assignment->effective_until;
            abort_unless(
                Carbon::parse($effectiveFrom)->lte(now())
                && ($effectiveUntil === null || Carbon::parse($effectiveUntil)->gt(now())),
                404,
            );

            $warehouse = DB::table('warehouses')
                ->join('stores', 'stores.id', '=', 'warehouses.store_id')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('warehouses.id', $assignment->warehouse_id)
                ->where('warehouses.is_active', true)
                ->where('stores.is_active', true)
                ->where('store_types.code', 'B2B')
                ->first(['warehouses.id', 'warehouses.store_id']);
            abort_unless($warehouse !== null, 404);
            $storeId = (int) $warehouse->store_id;
            $this->tenantScope->assertStore($actor, $storeId, 'drivers.b2b.manage', 'b2b');
            $this->tenantScope->assertStore($actor, $storeId, 'customers.view', 'b2b');

            if ($assignment->driver_id !== null) {
                abort_unless(DB::table('drivers')
                    ->where('id', $assignment->driver_id)
                    ->where('driver_type', 'b2b')
                    ->where('is_active', true)
                    ->exists(), 404);
            }

            $operatorId = $assignment->representative_user_id;
            if ($operatorId === null && $assignment->driver_id !== null) {
                $operatorId = DB::table('drivers')->where('id', $assignment->driver_id)->value('user_id');
            }
            abort_unless($operatorId !== null && DB::table('users')
                ->where('id', $operatorId)->where('is_active', true)->exists(), 404);

            // Idempotent retry is allowed only for exactly the same immutable
            // assignment, initiating actor and store. A reused key with a
            // different scope is a conflict, not an alternative trip.
            $existing = VanTripSession::query()
                ->where('van_id', $van->id)
                ->where('start_key', $key)
                ->first();
            if ($existing !== null) {
                abort_unless(
                    (int) $existing->van_assignment_id === (int) $assignment->id
                    && (int) $existing->store_id === $storeId
                    && (int) $existing->operator_user_id === (int) $operatorId
                    && (int) $existing->started_by_user_id === (int) $actor->id,
                    409,
                );
                $this->assertImmutableTripStartEvidence($existing);

                return $existing;
            }

            // The Van row lock serializes starts. The unique reserved_van_id
            // enforces this across MySQL/SQLite even if an alternate writer
            // bypasses the domain service. No automatic release on end.
            abort_if(VanTripSession::query()->where('reserved_van_id', $van->id)->exists(), 409);
            $trip = VanTripSession::query()->create([
                'public_id' => (string) Str::uuid(),
                'van_assignment_id' => $assignment->id,
                'van_id' => $van->id,
                'warehouse_id' => $warehouse->id,
                'store_id' => $storeId,
                'operator_user_id' => $operatorId,
                'started_by_user_id' => $actor->id,
                'reserved_van_id' => $van->id,
                'start_key' => $key,
                'status' => 'active',
                'started_at' => now(),
            ]);
            $this->recordEvent($trip, 1, 'started', (int) $actor->id, $key);

            return $trip;
        }, 3);
    }

    public function endOperationalTrip(User $actor, string $publicId, string $key): VanTripSession
    {
        $this->assertValidKey($key);
        abort_unless($actor->is_active, 403);

        return DB::transaction(function () use ($actor, $publicId, $key): VanTripSession {
            $trip = VanTripSession::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $this->tenantScope->assertStore($actor, (int) $trip->store_id, 'drivers.b2b.manage', 'b2b');
            $this->tenantScope->assertStore($actor, (int) $trip->store_id, 'customers.view', 'b2b');
            // Handoff/override has no immutable source contract yet. Even
            // another privileged user may not end a peer's trip by guessing
            // its public ID, until audited transfer authority exists.
            abort_unless((int) $trip->started_by_user_id === (int) $actor->id, 409);
            $this->assertImmutableTripStartEvidence($trip);

            if ($trip->status === 'ended') {
                abort_unless($trip->end_key === $key && (int) $trip->ended_by_user_id === (int) $actor->id, 409);

                return $trip;
            }

            abort_unless($trip->status === 'active', 409);
            abort_unless($key !== $trip->start_key, 409);
            $trip->forceFill([
                'status' => 'ended',
                'end_key' => $key,
                'ended_at' => now(),
                'ended_by_user_id' => $actor->id,
            ])->save();

            // Operational ending explicitly does NOT clear reserved_van_id,
            // post to AR/GL, move stock, or certify a closeout.
            $this->recordEvent($trip, 2, 'ended', (int) $actor->id, $key);

            return $trip;
        }, 3);
    }

    /**
     * Resolve a trustworthy *active trip identity* for a downstream B2B
     * custody writer. Caller-supplied Van, store and warehouse IDs must never
     * independently authorize a physical movement: they come from the
     * immutable trip and its current, scoped assignment instead.
     *
     * This is NOT evidence of loaded stock, legal title, a committed movement,
     * or finance/GL clearance. A caller must still establish those authorities
     * and perform its own idempotent posting in the same outer transaction.
     */
    public function requireActiveCustodyContext(User $actor, int $assignmentId, string $publicId): VanTripSession
    {
        abort_unless($actor->is_active, 403);

        return DB::transaction(function () use ($actor, $assignmentId, $publicId): VanTripSession {
            // Match start() lock ordering to avoid a trip-start/read deadlock.
            $assignment = VanAssignment::query()->lockForUpdate()->findOrFail($assignmentId);
            $van = Van::query()->whereKey($assignment->van_id)->lockForUpdate()->firstOrFail();
            $trip = VanTripSession::query()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->tenantScope->assertStore($actor, (int) $trip->store_id, 'drivers.b2b.manage', 'b2b');
            $this->tenantScope->assertStore($actor, (int) $trip->store_id, 'customers.view', 'b2b');
            abort_unless((int) $trip->van_assignment_id === (int) $assignment->id, 404);

            // Operational ending keeps the physical reservation. It does not
            // authorize new loads, even if the reserved Van slot still exists.
            abort_unless(
                $trip->status === 'active'
                && $trip->ended_at === null
                && (int) $trip->reserved_van_id === (int) $trip->van_id,
                409,
            );
            abort_unless(
                $assignment->status === 'active'
                && $van->status === 'active'
                && (int) $assignment->van_id === (int) $trip->van_id
                && (int) $assignment->warehouse_id === (int) $trip->warehouse_id,
                404,
            );
            abort_unless(
                Carbon::parse($assignment->effective_from)->lte(now())
                && ($assignment->effective_until === null || Carbon::parse($assignment->effective_until)->gt(now())),
                404,
            );

            $warehouse = DB::table('warehouses')
                ->join('stores', 'stores.id', '=', 'warehouses.store_id')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('warehouses.id', $trip->warehouse_id)
                ->where('warehouses.is_active', true)
                ->where('stores.is_active', true)
                ->where('store_types.code', 'B2B')
                ->first(['warehouses.id', 'warehouses.store_id']);
            abort_unless($warehouse !== null && (int) $warehouse->store_id === (int) $trip->store_id, 404);

            if ($assignment->driver_id !== null) {
                abort_unless(DB::table('drivers')
                    ->where('id', $assignment->driver_id)
                    ->where('driver_type', 'b2b')
                    ->where('is_active', true)
                    ->exists(), 404);
            }
            $operatorId = $assignment->representative_user_id;
            if ($operatorId === null && $assignment->driver_id !== null) {
                $operatorId = DB::table('drivers')->where('id', $assignment->driver_id)->value('user_id');
            }
            abort_unless(
                $operatorId !== null
                && (int) $operatorId === (int) $trip->operator_user_id
                && DB::table('users')->where('id', $operatorId)->where('is_active', true)->exists(),
                404,
            );

            // An orphan/corrupted trip row without immutable start evidence
            // cannot authorize custody activity or masquerade as a replay.
            $this->assertImmutableTripStartEvidence($trip);

            return $trip;
        }, 3);
    }

    /**
     * A row or an idempotency key alone is not trusted evidence of a Van
     * trip. Require the original immutable start event and the unreleased
     * physical Van reservation for both replay and operational completion.
     *
     * This does not prove stock ownership or authorize financial closeout.
     */
    private function assertImmutableTripStartEvidence(VanTripSession $trip): void
    {
        abort_unless(
            (int) $trip->van_id > 0
            && (int) $trip->reserved_van_id === (int) $trip->van_id,
            409,
        );
        abort_unless(DB::table('van_trip_session_events')
            ->where('van_trip_session_id', $trip->id)
            ->where('sequence', 1)
            ->where('kind', 'started')
            ->where('actor_user_id', $trip->started_by_user_id)
            ->where('idempotency_key', $trip->start_key)
            ->exists(), 409);
    }

    private function assertValidKey(string $key): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,119}\z/D', $key) !== 1) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Invalid Van trip idempotency key.'],
            ]);
        }
    }

    private function recordEvent(
        VanTripSession $trip,
        int $sequence,
        string $kind,
        int $actorId,
        string $key,
    ): void {
        DB::table('van_trip_session_events')->insert([
            'van_trip_session_id' => $trip->id,
            'sequence' => $sequence,
            'kind' => $kind,
            'actor_user_id' => $actorId,
            'idempotency_key' => $key,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
