<?php

namespace Tests\Feature;

use App\Models\FleetCurrentLocation;
use App\Models\Role;
use App\Models\User;
use App\Models\VanAssignment;
use App\Models\VanVisit;
use App\Services\VanRegistryService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class V2VanTripActivityAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_bilingual_van_trip_activity_is_discoverable_from_the_canonical_visits_page(): void
    {
        $admin = $this->superAdmin();

        foreach (['en', 'ar'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin->fresh())
                ->get(route('admin.field-operations.visits', ['trip_view' => 'trips']))
                ->assertOk()
                ->assertSee('data-v2-van-activity-tabs', false)
                ->assertSee('data-v2-van-trip-activity', false)
                ->assertSee('data-v2-van-trip-filters', false)
                ->assertSee(__('field_operations.trip_activity.tab', [], $locale))
                ->assertSee(__('field_operations.trip_activity.evidence_notice', [], $locale));
        }
    }

    public function test_trip_activity_is_read_only_and_excludes_retail_visits_even_for_super_admin(): void
    {
        $admin = $this->superAdmin();

        $this->recordVisit($admin, 'b2b', 'V2-P276-WHOLESALE');
        $this->recordVisit($admin, 'b2c', 'V2-P276-RETAIL');

        $this->actingAs($admin)
            ->get(route('admin.field-operations.visits', ['trip_view' => 'trips']))
            ->assertOk()
            ->assertSee('data-trip-route="V2-P276-WHOLESALE"', false)
            ->assertDontSee('data-trip-route="V2-P276-RETAIL"', false)
            ->assertDontSee('name="trip_id"', false)
            ->assertDontSee('name="settlement_amount"', false);

        $this->assertDatabaseCount('van_visits', 2);
    }

    public function test_exact_route_and_status_filters_do_not_reveal_other_van_activity(): void
    {
        $admin = $this->superAdmin();
        $this->recordVisit($admin, 'b2b', 'V2-P276-SELECTED', 'started');
        $this->recordVisit($admin, 'b2b', 'V2-P276-OTHER', 'planned');

        $this->actingAs($admin)
            ->get(route('admin.field-operations.visits', [
                'trip_view' => 'trips',
                'trip_route' => 'V2-P276-SELECTED',
                'trip_status' => 'started',
            ]))
            ->assertOk()
            ->assertSee('data-trip-route="V2-P276-SELECTED"', false)
            ->assertDontSee('data-trip-route="V2-P276-OTHER"', false);
    }

    public function test_trip_route_selector_ignores_unrelated_live_fleet_route_keys(): void
    {
        $admin = $this->superAdmin();
        $this->recordVisit($admin, 'b2b', 'V2-P276-AUTHORIZED-VISIT-ROUTE');

        // Live GPS is not a source of authority for the B2B visit-derived
        // trip tab. A forged/unassigned Retail location must not appear here.
        FleetCurrentLocation::query()->create([
            'actor_type' => 'van',
            'actor_id' => 991276,
            'route_key' => 'V2-P276-FOREIGN-LIVE-ROUTE',
            'store_id' => 987654,
            'channel' => 'b2c',
            'latitude' => 29.3375000,
            'longitude' => 47.9900000,
            'captured_at' => now(),
            'received_at' => now(),
            'source_app' => 'van',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.field-operations.visits', ['trip_view' => 'trips']))
            ->assertOk()
            ->assertSee('value="V2-P276-AUTHORIZED-VISIT-ROUTE"', false)
            ->assertDontSee('V2-P276-FOREIGN-LIVE-ROUTE');

        $this->assertDatabaseCount('van_visits', 1);
        $this->assertDatabaseCount('fleet_current_locations', 1);
    }

    public function test_forged_metadata_van_id_without_authorized_warehouse_assignment_never_exposes_van_code(): void
    {
        $admin = $this->superAdmin();
        $unassignedVan = app(VanRegistryService::class)
            ->createVan(['code' => 'V2-P276-UNASSIGNED-VAN-SECRET']);

        VanVisit::query()->create([
            'actor_user_id' => $admin->id,
            'customer_type' => 'b2b',
            'customer_id' => 10001,
            'store_id' => null,
            'status' => 'planned',
            'planned_at' => now(),
            'metadata' => [
                'route_key' => 'V2-P276-VISIBLE-ROUTE',
                'van_id' => $unassignedVan->id,
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.field-operations.visits', ['trip_view' => 'trips']))
            ->assertOk()
            ->assertSee('data-trip-route="V2-P276-VISIBLE-ROUTE"', false)
            ->assertDontSee('V2-P276-UNASSIGNED-VAN-SECRET');

        $this->assertDatabaseCount('van_visits', 1);
    }

    public function test_van_label_requires_current_active_assignment_van_and_wholesale_warehouse(): void
    {
        $admin = $this->superAdmin();
        $warehouseId = app(WholesalePrincipal::class)->warehouseId();
        $van = app(VanRegistryService::class)->createVan([
            'code' => 'V2-P276-AUTHORIZED-ONLY-VAN',
        ]);
        $assignment = VanAssignment::query()->create([
            'public_id' => (string) Str::uuid(),
            'van_id' => $van->id,
            'warehouse_id' => $warehouseId,
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => now()->subHour(),
            'effective_until' => now()->addHour(),
            'representative_user_id' => $admin->id,
            'created_by' => $admin->id,
        ]);

        VanVisit::query()->create([
            'actor_user_id' => $admin->id,
            'customer_type' => 'b2b',
            'customer_id' => 10001,
            'store_id' => null,
            'status' => 'planned',
            'planned_at' => now(),
            'metadata' => [
                'route_key' => 'V2-P276-AUTHORIZED-ROUTE',
                'van_id' => $van->id,
                'van_assignment_id' => $assignment->id,
            ],
        ]);

        $this->actingAs($admin);
        $url = route('admin.field-operations.visits', ['trip_view' => 'trips']);
        // The page also has a separate admin assignment selector. Test the
        // specific B2B trip row; unrelated selector labels are not trip grants.
        $assertTripLabel = function (string $label) use ($url): void {
            $response = $this->get($url)->assertOk();
            $this->assertMatchesRegularExpression(
                '/<tr data-trip-route="V2-P276-AUTHORIZED-ROUTE">\\s*<td>'.preg_quote($label, '/').'<\\/td>/u',
                (string) $response->getContent(),
            );
        };

        $assertTripLabel('V2-P276-AUTHORIZED-ONLY-VAN');

        $assignment->forceFill(['effective_until' => now()->subMinute()])->save();
        $assertTripLabel('—');

        $assignment->forceFill(['effective_from' => now()->addDay(), 'effective_until' => now()->addDays(2)])->save();
        $assertTripLabel('—');

        $assignment->forceFill([
            'effective_from' => now()->subHour(),
            'effective_until' => now()->addHour(),
            'status' => 'inactive',
        ])->save();
        $assertTripLabel('—');

        $assignment->forceFill(['status' => 'active'])->save();
        $van->forceFill(['status' => 'inactive'])->save();
        $assertTripLabel('—');

        $van->forceFill(['status' => 'active'])->save();
        DB::table('warehouses')->where('id', $warehouseId)->update(['is_active' => false]);
        $assertTripLabel('—');

        $this->assertDatabaseCount('van_visits', 1);
    }

    public function test_forged_metadata_cannot_attribute_a_visit_to_another_authorized_van_or_operator(): void
    {
        $admin = $this->superAdmin();
        $warehouseId = app(WholesalePrincipal::class)->warehouseId();
        $legitimateVan = app(VanRegistryService::class)->createVan(['code' => 'V2-P276-LEGITIMATE-TRIP-VAN']);
        $otherVan = app(VanRegistryService::class)->createVan(['code' => 'V2-P276-FORGED-TRIP-VAN']);
        $assignment = VanAssignment::query()->create([
            'public_id' => (string) Str::uuid(),
            'van_id' => $legitimateVan->id,
            'warehouse_id' => $warehouseId,
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => now()->subHour(),
            'effective_until' => now()->addHour(),
            'representative_user_id' => $admin->id,
            'created_by' => $admin->id,
        ]);
        VanAssignment::query()->create([
            'public_id' => (string) Str::uuid(),
            'van_id' => $otherVan->id,
            'warehouse_id' => $warehouseId,
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => now()->subHour(),
            'effective_until' => now()->addHour(),
            'representative_user_id' => $admin->id,
            'created_by' => $admin->id,
        ]);
        $visit = VanVisit::query()->create([
            'actor_user_id' => $admin->id,
            'customer_type' => 'b2b',
            'customer_id' => 10001,
            'store_id' => null,
            'status' => 'planned',
            'planned_at' => now(),
            'metadata' => [
                'route_key' => 'V2-P276-FORGED-TRIP-ROUTE',
                'van_id' => $otherVan->id,
                'van_assignment_id' => $assignment->id,
            ],
        ]);

        $url = route('admin.field-operations.visits', ['trip_view' => 'trips']);
        $assertNeutralTripLabel = function () use ($url): void {
            $response = $this->actingAs($this->superAdmin())->get($url)->assertOk();
            $this->assertMatchesRegularExpression(
                '/<tr data-trip-route="V2-P276-FORGED-TRIP-ROUTE">\\s*<td>—<\\/td>/u',
                (string) $response->getContent(),
            );
        };

        $assertNeutralTripLabel();
        $visit->forceFill(['metadata' => [
            'route_key' => 'V2-P276-FORGED-TRIP-ROUTE',
            'van_id' => $legitimateVan->id,
            'van_assignment_id' => $assignment->id,
        ]])->save();
        $assignment->forceFill(['representative_user_id' => User::factory()->create()->id])->save();
        $assertNeutralTripLabel();

        $this->assertDatabaseCount('van_visits', 1);
    }


    public function test_planned_visit_rejects_inactive_or_cross_channel_assignment_provenance_before_writing(): void
    {
        $admin = $this->superAdmin();
        $wholesaleStoreId = app(WholesalePrincipal::class)->storeId();
        $warehouseId = app(WholesalePrincipal::class)->warehouseId();
        $customerId = (int) DB::table('b2b_customers')->insertGetId([
            'name' => 'P276 assigned Wholesale customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $van = app(VanRegistryService::class)->createVan(['code' => 'V2-P276-PLANNED-VAN']);
        $assignment = VanAssignment::query()->create([
            'public_id' => (string) Str::uuid(),
            'van_id' => $van->id,
            'warehouse_id' => $warehouseId,
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => now()->subHour(),
            'effective_until' => now()->addHour(),
            'representative_user_id' => $admin->id,
            'created_by' => $admin->id,
        ]);
        $payload = [
            'assignment_id' => $assignment->id,
            'customer_type' => 'b2b',
            'customer_id' => $customerId,
            'route_key' => 'P276-PLANNED-TRIP',
        ];
        $url = route('admin.field-operations.visits.store');
        $this->actingAs($admin)->post($url, $payload)->assertRedirect();
        $this->assertDatabaseHas('van_visits', [
            'customer_type' => 'b2b',
            'customer_id' => $customerId,
            'store_id' => $wholesaleStoreId,
        ]);
        $this->assertDatabaseCount('van_visits', 1);

        $van->forceFill(['status' => 'inactive'])->save();
        $this->post($url, $payload)->assertNotFound();
        $van->forceFill(['status' => 'active'])->save();

        DB::table('warehouses')->where('id', $warehouseId)->update(['is_active' => false]);
        $this->post($url, $payload)->assertNotFound();
        DB::table('warehouses')->where('id', $warehouseId)->update(['is_active' => true]);

        $otherB2bStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'P276-OTHER-B2B',
            'name' => 'P276 another Wholesale store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->post($url, $payload + ['store_id' => $otherB2bStoreId])->assertNotFound();

        $retailStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P276-RETAIL',
            'name' => 'P276 Retail store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('warehouses')->where('id', $warehouseId)->update(['store_id' => $retailStoreId]);
        $this->post($url, $payload)->assertNotFound();
        DB::table('warehouses')->where('id', $warehouseId)->update(['store_id' => $wholesaleStoreId]);

        $inactiveOperator = User::factory()->create(['is_active' => false]);
        $assignment->forceFill(['representative_user_id' => $inactiveOperator->id])->save();
        $this->post($url, $payload)->assertNotFound();

        $this->assertDatabaseCount('van_visits', 1);
    }

    public function test_user_without_b2b_fleet_authority_cannot_open_trip_activity(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail());

        $this->actingAs($user)
            ->get(route('admin.field-operations.visits', ['trip_view' => 'trips']))
            ->assertForbidden();
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $admin;
    }

    private function recordVisit(User $actor, string $customerType, string $route, string $status = 'planned'): void
    {
        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => $customerType,
            'customer_id' => 10001,
            'store_id' => null,
            'status' => $status,
            'planned_at' => now(),
            'metadata' => ['route_key' => $route],
        ]);
    }
}
