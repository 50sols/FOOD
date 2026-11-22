<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\RestaurantWorkforceService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class V2WorkforceClockShiftScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_shift_is_hidden_before_action_validation_ar_en_and_owned_clock_in_still_works(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $createStore = function (string $prefix): int {
            $id = (int) DB::table('stores')->insertGetId([
                'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
                'code' => $prefix.'-'.Str::upper(Str::random(8)),
                'name' => $prefix,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->enableRestaurantSaasForTestStore($id);

            return $id;
        };
        $ownedStore = $createStore('WORKFORCE-OWN');
        $foreignStore = $createStore('WORKFORCE-FOREIGN');

        $createBranch = function (int $storeId, string $code): SmartOrderingBranch {
            $siteId = (int) DB::table('smart_ordering_sites')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'store_id' => $storeId,
                'slug' => strtolower($code).'-'.Str::lower(Str::random(8)),
                'runtime_state' => 'ENABLED',
                'master_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return SmartOrderingBranch::query()->create([
                'public_id' => (string) Str::uuid(),
                'site_id' => $siteId,
                'code' => $code,
                'name_en' => $code,
                'timezone' => 'Asia/Kuwait',
                'is_active' => true,
                'accepting_orders' => true,
                'supports_dine_in' => true,
                'supports_pickup' => true,
                'supports_delivery' => true,
                'allow_scheduled' => true,
                'pickup_lead_minutes' => 0,
                'max_schedule_days' => 7,
                'service_charge' => 0,
            ]);
        };
        $ownedBranch = $createBranch($ownedStore, 'OWN');
        $siblingBranch = $createBranch($ownedStore, 'SIBLING');
        $foreignBranch = $createBranch($foreignStore, 'FOREIGN');

        $actor = User::query()->create([
            'name' => 'Clock scope test manager',
            'email' => 'v2-clock-'.Str::lower(Str::random(10)).'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $ownedStore,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(RestaurantWorkforceService::class)->assignStaff($ownedBranch, (int) $actor->id, $actor);

        $createShift = function (SmartOrderingBranch $branch, int $userId): string {
            $uuid = (string) Str::uuid();
            DB::table('restaurant_shifts')->insert([
                'public_id' => $uuid,
                'branch_id' => (int) $branch->id,
                'user_id' => $userId,
                'starts_at' => now()->addHour(),
                'ends_at' => now()->addHours(8),
                'status' => 'PUBLISHED',
                'notes' => null,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $uuid;
        };
        $ownedShift = $createShift($ownedBranch, (int) $actor->id);
        $siblingShift = $createShift($siblingBranch, (int) $actor->id);
        $foreignShift = $createShift($foreignBranch, (int) $actor->id);
        $missingShift = (string) Str::uuid();
        $url = route('admin.smart-ordering.workforce.clock', $ownedBranch->public_id);
        $initialClockCount = DB::table('restaurant_time_clock_sessions')->count();
        $initialAuditCount = DB::table('audit_logs')->count();
        $originalLocale = app()->getLocale();

        try {
            foreach (['ar', 'en'] as $locale) {
                $actor->forceFill(['locale' => $locale])->saveQuietly();
                app()->setLocale($locale);
                foreach ([$siblingShift, $foreignShift, $missingShift] as $deniedShift) {
                    foreach ([
                        ['shift_public_id' => $deniedShift],
                        ['shift_public_id' => $deniedShift, 'action' => 'unknown'],
                        ['shift_public_id' => $deniedShift, 'action' => 'clock_in'],
                    ] as $payload) {
                        $this->actingAs($actor->fresh())->postJson($url, $payload)->assertNotFound();
                    }
                }
                foreach ([
                    ['shift_public_id' => 'invalid', 'action' => 'clock_in'],
                    ['shift_public_id' => ['nested'], 'action' => 'clock_in'],
                    ['shift_public_id' => $ownedShift, 'action' => 'unknown'],
                ] as $payload) {
                    $this->actingAs($actor->fresh())->postJson($url, $payload)->assertUnprocessable();
                }
            }
        } finally {
            app()->setLocale($originalLocale);
        }

        $this->assertSame($initialClockCount, DB::table('restaurant_time_clock_sessions')->count());
        $this->assertSame($initialAuditCount, DB::table('audit_logs')->count());
        $this->actingAs($actor->fresh())->post($url, [
            'action' => 'clock_in', 'shift_public_id' => $ownedShift,
        ])->assertRedirect();
        $ownedShiftId = (int) DB::table('restaurant_shifts')
            ->where('public_id', $ownedShift)->value('id');
        $foreignShiftId = (int) DB::table('restaurant_shifts')
            ->where('public_id', $foreignShift)->value('id');
        $this->assertDatabaseHas('restaurant_time_clock_sessions', [
            'branch_id' => (int) $ownedBranch->id,
            'user_id' => (int) $actor->id,
            'shift_id' => $ownedShiftId,
            'status' => 'OPEN',
        ]);
        $this->assertDatabaseMissing('restaurant_time_clock_sessions', [
            'branch_id' => (int) $ownedBranch->id,
            'shift_id' => $foreignShiftId,
        ]);
        $this->assertSame($initialClockCount + 1, DB::table('restaurant_time_clock_sessions')->count());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => (int) $actor->id,
            'event' => 'smart_ordering.workforce.time_clock.clock_in',
        ]);
    }
}
