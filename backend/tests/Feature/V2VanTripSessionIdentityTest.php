<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VanAssignment;
use App\Services\VanRegistryService;
use App\Services\VanTripSessionService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class V2VanTripSessionIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_verified_wholesale_assignment_creates_one_stable_trip_identity_with_idempotent_replay(): void
    {
        [$actor, $assignment] = $this->fixture();
        $registry = app(VanTripSessionService::class);

        $trip = $registry->start($actor, (int) $assignment->id, 'P276-start-replay-001');
        $replayed = $registry->start($actor, (int) $assignment->id, 'P276-start-replay-001');

        $this->assertSame((int) $trip->id, (int) $replayed->id);
        $this->assertTrue(Str::isUuid($trip->public_id));
        $this->assertSame('active', $trip->status);
        $this->assertSame((int) app(WholesalePrincipal::class)->storeId(), (int) $trip->store_id);
        $this->assertSame((int) $assignment->warehouse_id, (int) $trip->warehouse_id);
        $this->assertSame((int) $assignment->representative_user_id, (int) $trip->operator_user_id);
        $this->assertSame((int) $trip->van_id, (int) $trip->reserved_van_id);
        $this->assertDatabaseCount('van_trip_sessions', 1);
        $this->assertDatabaseHas('van_trip_session_events', [
            'van_trip_session_id' => $trip->id,
            'sequence' => 1,
            'kind' => 'started',
            'actor_user_id' => $actor->id,
        ]);
        $this->assertDatabaseCount('van_trip_session_events', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_invalid_idempotency_keys_fail_before_any_database_mutation(): void
    {
        [$actor, $assignment] = $this->fixture();
        $registry = app(VanTripSessionService::class);

        foreach (['short', ' space-in-front-0001', '../path/traversal', str_repeat('x', 121)] as $key) {
            try {
                $registry->start($actor, (int) $assignment->id, $key);
                $this->fail('Malformed trip idempotency key must not start a session.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('idempotency_key', $exception->errors());
            }
        }

        $this->assertDatabaseCount('van_trip_sessions', 0);
        $this->assertDatabaseCount('van_trip_session_events', 0);
    }

    public function test_offline_replay_survives_expired_assignment_without_reauthorizing_new_work(): void
    {
        [$actor, $assignment] = $this->fixture();
        $registry = app(VanTripSessionService::class);
        $trip = $registry->start($actor, (int) $assignment->id, 'P276-original-offline-key');

        $assignment->forceFill([
            'status' => 'revoked',
            'effective_until' => now()->subMinute(),
        ])->save();
        DB::table('vans')->where('id', $assignment->van_id)->update(['status' => 'inactive']);

        // Immutable idempotency wins over mutable assignment/Van readiness.
        // The original authorized actor may retrieve only the recorded result.
        $replayed = $registry->start($actor, (int) $assignment->id, 'P276-original-offline-key');
        $this->assertSame((int) $trip->id, (int) $replayed->id);
        $this->assertSame($trip->public_id, $replayed->public_id);

        $this->expectNotFoundOrForbidden(
            fn () => $registry->start($actor, (int) $assignment->id, 'P276-new-offline-key-01')
        );
        $other = $this->superAdmin();
        $this->expectHttpConflict(
            fn () => $registry->start($other, (int) $assignment->id, 'P276-original-offline-key')
        );

        // Even a matching historical key is not permission to read another
        // tenant's retained trip after the original store has been disabled.
        DB::table('stores')->where('id', $trip->store_id)->update(['is_active' => false]);
        $this->expectNotFoundOrForbidden(
            fn () => $registry->start($actor, (int) $assignment->id, 'P276-original-offline-key')
        );
        DB::table('stores')->where('id', $trip->store_id)->update(['is_active' => true]);

        $actor->forceFill(['is_active' => false])->save();
        $this->expectNotFoundOrForbidden(
            fn () => $registry->start($actor->fresh(), (int) $assignment->id, 'P276-original-offline-key')
        );

        $this->assertDatabaseCount('van_trip_sessions', 1);
        $this->assertDatabaseCount('van_trip_session_events', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_end_of_operational_trip_never_implies_financial_or_physical_closeout(): void
    {
        [$actor, $assignment] = $this->fixture();
        $registry = app(VanTripSessionService::class);
        $trip = $registry->start($actor, (int) $assignment->id, 'P276-begin-00002');

        $ended = $registry->endOperationalTrip($actor, $trip->public_id, 'P276-finish-0002');
        $retried = $registry->endOperationalTrip($actor, $trip->public_id, 'P276-finish-0002');
        $this->assertSame((int) $trip->id, (int) $retried->id);
        $this->assertSame('ended', $ended->status);
        $this->assertNotNull($ended->ended_at);
        $this->assertSame((int) $trip->van_id, (int) $ended->reserved_van_id);
        $this->assertDatabaseCount('van_trip_session_events', 2);

        $this->expectHttpConflict(fn () => $registry->start($actor, (int) $assignment->id, 'P276-new-key-0003'));
        $this->expectHttpConflict(fn () => $registry->endOperationalTrip($actor, $trip->public_id, 'P276-forged-end-3'));
        $this->assertDatabaseCount('van_trip_sessions', 1);
        $this->assertDatabaseCount('van_trip_session_events', 2);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_forged_replay_by_a_different_actor_or_different_assignment_cannot_steal_trip_identity(): void
    {
        [$actor, $assignment] = $this->fixture();
        $registry = app(VanTripSessionService::class);
        $trip = $registry->start($actor, (int) $assignment->id, 'P276-original-0003');

        $other = $this->superAdmin();
        $this->expectHttpConflict(fn () => $registry->start($other, (int) $assignment->id, 'P276-original-0003'));
        $this->expectHttpConflict(fn () => $registry->endOperationalTrip($other, $trip->public_id, 'P276-other-end-4'));

        $secondAssignment = VanAssignment::query()->create([
            'public_id' => (string) Str::uuid(),
            'van_id' => $assignment->van_id,
            'warehouse_id' => $assignment->warehouse_id,
            'representative_user_id' => $actor->id,
            'created_by' => $actor->id,
            'status' => 'active',
            'effective_from' => now()->subMinute(),
            'effective_until' => now()->addHour(),
        ]);
        $this->expectHttpConflict(fn () => $registry->start($actor, (int) $secondAssignment->id, 'P276-original-0003'));
        $this->assertDatabaseCount('van_trip_sessions', 1);
        $this->assertDatabaseCount('van_trip_session_events', 1);
    }

    public function test_missing_b2b_authority_and_inactive_actor_are_denied_without_trip_or_audit_write(): void
    {
        [$actor, $assignment] = $this->fixture();
        $retail = User::factory()->create(['is_active' => true]);
        $retail->roles()->attach(Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail());

        $this->expectNotFoundOrForbidden(fn () => app(VanTripSessionService::class)
            ->start($retail, (int) $assignment->id, 'P276-retail-actor-1'));

        $actor->forceFill(['is_active' => false])->save();
        $this->expectNotFoundOrForbidden(fn () => app(VanTripSessionService::class)
            ->start($actor->fresh(), (int) $assignment->id, 'P276-inactive-user-1'));

        $this->assertDatabaseCount('van_trip_sessions', 0);
        $this->assertDatabaseCount('van_trip_session_events', 0);
    }

    public function test_rejected_inactive_van_revoked_assignment_disabled_warehouse_and_retail_custody_are_non_mutating(): void
    {
        [$actor, $assignment] = $this->fixture();
        $registry = app(VanTripSessionService::class);
        $vanId = (int) $assignment->van_id;
        $warehouseId = (int) $assignment->warehouse_id;

        DB::table('vans')->where('id', $vanId)->update(['status' => 'inactive']);
        $this->expectNotFoundOrForbidden(fn () => $registry->start($actor, (int) $assignment->id, 'P276-inactive-van-1'));
        DB::table('vans')->where('id', $vanId)->update(['status' => 'active']);

        $assignment->forceFill(['status' => 'revoked'])->save();
        $this->expectNotFoundOrForbidden(fn () => $registry->start($actor, (int) $assignment->id, 'P276-revoked-asn-01'));
        $assignment->forceFill(['status' => 'active'])->save();

        DB::table('warehouses')->where('id', $warehouseId)->update(['is_active' => false]);
        $this->expectNotFoundOrForbidden(fn () => $registry->start($actor, (int) $assignment->id, 'P276-disabled-wh-1'));
        DB::table('warehouses')->where('id', $warehouseId)->update(['is_active' => true]);

        $retailStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P276-RETAIL-CUSTODY',
            'name' => 'P276 retail custody',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('warehouses')->where('id', $warehouseId)->update(['store_id' => $retailStoreId]);
        $this->expectNotFoundOrForbidden(fn () => $registry->start($actor, (int) $assignment->id, 'P276-retail-wh-001'));

        $this->assertDatabaseCount('van_trip_sessions', 0);
        $this->assertDatabaseCount('van_trip_session_events', 0);
    }

    public function test_database_uniqueness_prevents_a_second_reserved_trip_even_without_domain_writer(): void
    {
        [$actor, $assignment] = $this->fixture();
        $trip = app(VanTripSessionService::class)->start($actor, (int) $assignment->id, 'P276-unique-first');
        $payload = [
            'public_id' => (string) Str::uuid(),
            'van_assignment_id' => $assignment->id,
            'van_id' => $trip->van_id,
            'warehouse_id' => $trip->warehouse_id,
            'store_id' => $trip->store_id,
            'operator_user_id' => $trip->operator_user_id,
            'started_by_user_id' => $actor->id,
            'reserved_van_id' => $trip->van_id,
            'status' => 'active',
            'start_key' => 'P276-unique-second',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            DB::table('van_trip_sessions')->insert($payload);
            $this->fail('Database must reject a duplicate Van trip slot.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        // Assignment reassignment cannot generate a second physical trip
        // identity for the same immutable offline request key.
        $differentVan = app(VanRegistryService::class)->createVan([
            'code' => 'P276-DIFFERENT-VAN-'.Str::random(6),
        ]);
        $secondPayload = array_replace($payload, [
            'public_id' => (string) Str::uuid(),
            'van_id' => $differentVan->id,
            'reserved_van_id' => $differentVan->id,
            'start_key' => $trip->start_key,
        ]);
        try {
            DB::table('van_trip_sessions')->insert($secondPayload);
            $this->fail('Database must reject duplicate start keys under the same assignment.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->assertDatabaseCount('van_trip_sessions', 1);
        $this->assertDatabaseCount('van_trip_session_events', 1);
    }

    public function test_custody_consumer_requires_audited_active_b2b_trip_with_live_permission_and_no_stock_write(): void
    {
        [$actor, $assignment] = $this->fixture();
        $service = app(VanTripSessionService::class);
        $trip = $service->start($actor, (int) $assignment->id, 'P276-consumer-start-001');

        $resolved = $service->requireActiveCustodyContext($actor, (int) $assignment->id, $trip->public_id);
        $this->assertSame($trip->id, $resolved->id);
        $this->assertSame($trip->store_id, $resolved->store_id);
        $this->assertSame($trip->van_id, $resolved->reserved_van_id);

        // Even a correctly scoped source row without its immutable start
        // event must not establish trusted physical-custody provenance.
        DB::table('van_trip_session_events')->where('van_trip_session_id', $trip->id)
            ->where('sequence', 1)->update(['kind' => 'tampered']);
        $this->expectHttpConflict(fn () => $service->requireActiveCustodyContext(
            $actor, (int) $assignment->id, $trip->public_id
        ));
        DB::table('van_trip_session_events')->where('van_trip_session_id', $trip->id)
            ->where('sequence', 1)->update(['kind' => 'started']);

        $retailActor = User::factory()->create(['is_active' => true]);
        $retailActor->roles()->attach(Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail());
        $this->expectNotFoundOrForbidden(fn () => $service->requireActiveCustodyContext(
            $retailActor, (int) $assignment->id, $trip->public_id
        ));

        // A freed/corrupted reservation cannot authorize load posting.
        DB::table('van_trip_sessions')->where('id', $trip->id)->update(['reserved_van_id' => null]);
        $this->expectHttpConflict(fn () => $service->requireActiveCustodyContext(
            $actor, (int) $assignment->id, $trip->public_id
        ));
        DB::table('van_trip_sessions')->where('id', $trip->id)->update(['reserved_van_id' => $trip->van_id]);

        $service->endOperationalTrip($actor, $trip->public_id, 'P276-consumer-end-0001');
        $this->expectHttpConflict(fn () => $service->requireActiveCustodyContext(
            $actor, (int) $assignment->id, $trip->public_id
        ));

        $this->assertDatabaseCount('van_trip_sessions', 1);
        $this->assertDatabaseCount('van_trip_session_events', 2);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_custody_consumer_refuses_assignment_rebinding_expiry_and_mutated_warehouse(): void
    {
        [$actor, $assignment] = $this->fixture();
        $service = app(VanTripSessionService::class);
        $trip = $service->start($actor, (int) $assignment->id, 'P276-consumer-scope-01');

        $assignment->forceFill(['status' => 'revoked'])->save();
        $this->expectNotFoundOrForbidden(fn () => $service->requireActiveCustodyContext(
            $actor, (int) $assignment->id, $trip->public_id
        ));
        $assignment->forceFill(['status' => 'active', 'effective_until' => now()->subMinute()])->save();
        $this->expectNotFoundOrForbidden(fn () => $service->requireActiveCustodyContext(
            $actor, (int) $assignment->id, $trip->public_id
        ));
        $assignment->forceFill(['effective_until' => now()->addHour()])->save();

        $other = User::factory()->create(['is_active' => true]);
        $assignment->forceFill(['representative_user_id' => $other->id])->save();
        $this->expectNotFoundOrForbidden(fn () => $service->requireActiveCustodyContext(
            $actor, (int) $assignment->id, $trip->public_id
        ));
        $assignment->forceFill(['representative_user_id' => $actor->id])->save();

        $otherWarehouse = DB::table('warehouses')->where('id', '!=', $assignment->warehouse_id)->value('id');
        if ($otherWarehouse !== null) {
            $assignment->forceFill(['warehouse_id' => $otherWarehouse])->save();
            $this->expectNotFoundOrForbidden(fn () => $service->requireActiveCustodyContext(
                $actor, (int) $assignment->id, $trip->public_id
            ));
            $assignment->forceFill(['warehouse_id' => $trip->warehouse_id])->save();
        }

        DB::table('warehouses')->where('id', $trip->warehouse_id)->update(['is_active' => false]);
        $this->expectNotFoundOrForbidden(fn () => $service->requireActiveCustodyContext(
            $actor, (int) $assignment->id, $trip->public_id
        ));
        DB::table('warehouses')->where('id', $trip->warehouse_id)->update(['is_active' => true]);

        $this->assertSame($trip->id, $service->requireActiveCustodyContext(
            $actor, (int) $assignment->id, $trip->public_id
        )->id);
        $this->assertDatabaseCount('van_trip_sessions', 1);
        $this->assertDatabaseCount('van_trip_session_events', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_replay_and_operational_end_reject_unaudited_or_unreserved_trip_identity(): void
    {
        [$actor, $assignment] = $this->fixture();
        $service = app(VanTripSessionService::class);
        $trip = $service->start($actor, (int) $assignment->id, 'P276-audited-start-0001');

        // A valid looking trip row is not itself proof of an authorized
        // start. Retry must not trust forged/missing first-event provenance.
        DB::table('van_trip_session_events')
            ->where('van_trip_session_id', $trip->id)
            ->where('sequence', 1)
            ->update(['kind' => 'tampered']);
        $this->expectHttpConflict(fn () => $service->start(
            $actor, (int) $assignment->id, 'P276-audited-start-0001'
        ));
        $this->expectHttpConflict(fn () => $service->endOperationalTrip(
            $actor, $trip->public_id, 'P276-audited-end-0001'
        ));

        DB::table('van_trip_session_events')
            ->where('van_trip_session_id', $trip->id)
            ->where('sequence', 1)
            ->update(['kind' => 'started']);
        DB::table('van_trip_sessions')->where('id', $trip->id)
            ->update(['reserved_van_id' => null]);

        // An erased physical reservation cannot be treated as the same
        // immutable accepted start or a safe operational completion.
        $this->expectHttpConflict(fn () => $service->start(
            $actor, (int) $assignment->id, 'P276-audited-start-0001'
        ));
        $this->expectHttpConflict(fn () => $service->endOperationalTrip(
            $actor, $trip->public_id, 'P276-audited-end-0001'
        ));

        DB::table('van_trip_sessions')->where('id', $trip->id)
            ->update(['reserved_van_id' => $trip->van_id]);
        $this->assertSame($trip->id, $service->start(
            $actor, (int) $assignment->id, 'P276-audited-start-0001'
        )->id);
        $this->assertSame('ended', $service->endOperationalTrip(
            $actor, $trip->public_id, 'P276-audited-end-0001'
        )->status);

        $this->assertDatabaseCount('van_trip_sessions', 1);
        $this->assertDatabaseCount('van_trip_session_events', 2);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_ended_trip_replay_requires_immutable_end_event_and_timestamp_without_stock_or_finance_writes(): void
    {
        [$actor, $assignment] = $this->fixture();
        $service = app(VanTripSessionService::class);
        $trip = $service->start($actor, (int) $assignment->id, 'P276-end-proof-start-0001');
        $ended = $service->endOperationalTrip($actor, $trip->public_id, 'P276-end-proof-finish-0001');

        // An ended row with a forged or absent immutable end event cannot be
        // accepted as the original idempotent completion.
        DB::table('van_trip_session_events')->where('van_trip_session_id', $trip->id)
            ->where('sequence', 2)->update(['idempotency_key' => 'P276-forged-end-proof']);
        $this->expectHttpConflict(fn () => $service->endOperationalTrip(
            $actor, $trip->public_id, 'P276-end-proof-finish-0001'
        ));
        DB::table('van_trip_session_events')->where('van_trip_session_id', $trip->id)
            ->where('sequence', 2)->update(['idempotency_key' => 'P276-end-proof-finish-0001']);

        DB::table('van_trip_session_events')->where('van_trip_session_id', $trip->id)
            ->where('sequence', 2)->update(['kind' => 'started']);
        $this->expectHttpConflict(fn () => $service->endOperationalTrip(
            $actor, $trip->public_id, 'P276-end-proof-finish-0001'
        ));
        DB::table('van_trip_session_events')->where('van_trip_session_id', $trip->id)
            ->where('sequence', 2)->update(['kind' => 'ended']);

        DB::table('van_trip_sessions')->where('id', $trip->id)->update(['ended_at' => null]);
        $this->expectHttpConflict(fn () => $service->endOperationalTrip(
            $actor, $trip->public_id, 'P276-end-proof-finish-0001'
        ));
        DB::table('van_trip_sessions')->where('id', $trip->id)->update(['ended_at' => $ended->ended_at]);

        $this->assertSame($trip->id, $service->endOperationalTrip(
            $actor, $trip->public_id, 'P276-end-proof-finish-0001'
        )->id);

        DB::table('van_trip_session_events')->where('van_trip_session_id', $trip->id)
            ->where('sequence', 2)->delete();
        $this->expectHttpConflict(fn () => $service->endOperationalTrip(
            $actor, $trip->public_id, 'P276-end-proof-finish-0001'
        ));
        $this->assertDatabaseCount('van_trip_sessions', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    /** @return array{User, VanAssignment} */
    private function fixture(): array
    {
        $actor = $this->superAdmin();
        $warehouseId = app(WholesalePrincipal::class)->warehouseId();
        $van = app(VanRegistryService::class)->createVan([
            'code' => 'P276-SESSION-'.Str::random(8),
        ]);
        $assignment = VanAssignment::query()->create([
            'public_id' => (string) Str::uuid(),
            'van_id' => $van->id,
            'warehouse_id' => $warehouseId,
            'representative_user_id' => $actor->id,
            'created_by' => $actor->id,
            'status' => 'active',
            'effective_from' => now()->subHour(),
            'effective_until' => now()->addHour(),
        ]);

        return [$actor, $assignment];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function expectHttpConflict(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected an HTTP 409 from unauthorized trip mutation.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    private function expectNotFoundOrForbidden(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected unauthorized trip mutation to fail closed.');
        } catch (HttpException $exception) {
            $this->assertContains($exception->getStatusCode(), [403, 404]);
        }
    }
}
