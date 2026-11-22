<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class V2RestaurantTableRoutePrevalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_floor_and_table_put_enforce_route_scope_before_payload_validation_in_ar_and_en(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-TABLE-UPDATE-SCOPE',
            'name' => 'Scoped Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableRestaurantSaasForTestStore($storeId);

        $siteId = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'slug' => 'v2-floor-table-prevalidation',
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ownedBranch = SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'code' => 'v2-owned-dining',
            'name_en' => 'Owned Dining',
            'name_ar' => 'صالة محلية',
            'timezone' => 'Asia/Kuwait',
            'is_active' => true,
        ]);
        $siblingBranch = SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'code' => 'v2-sibling-dining',
            'name_en' => 'Sibling Dining',
            'name_ar' => 'صالة أخرى',
            'timezone' => 'Asia/Kuwait',
            'is_active' => true,
        ]);

        $actor = User::query()->create([
            'name' => 'Restaurant Scope Admin',
            'email' => 'v2-table-scope-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $floors = [];
        $tables = [];
        foreach ([$ownedBranch, $siblingBranch] as $branch) {
            $floorPublicId = (string) Str::uuid();
            $floorId = (int) DB::table('smart_ordering_floor_areas')->insertGetId([
                'public_id' => $floorPublicId,
                'branch_id' => $branch->getKey(),
                'name_en' => 'Original Floor',
                'name_ar' => 'الدور الأصلي',
                'sort_order' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $tablePublicId = (string) Str::uuid();
            $tableId = (int) DB::table('smart_ordering_tables')->insertGetId([
                'public_id' => $tablePublicId,
                'branch_id' => $branch->getKey(),
                'floor_area_id' => $floorId,
                'label' => 'Original Table',
                'capacity' => 2,
                'position_x' => 60,
                'position_y' => 60,
                'visual_width' => 160,
                'visual_height' => 120,
                'shape' => 'rectangle',
                'qr_reference' => (string) Str::uuid(),
                'token_hash' => hash('sha256', (string) Str::uuid()),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $floors[] = ['id' => $floorId, 'public_id' => $floorPublicId];
            $tables[] = ['id' => $tableId, 'public_id' => $tablePublicId];
        }

        $floorUrl = fn (string $id): string => route('admin.smart-ordering.tables.floors.update', [
            $ownedBranch->public_id, $id,
        ]);
        $tableUrl = fn (string $id): string => route('admin.smart-ordering.tables.update', [
            $ownedBranch->public_id, $id,
        ]);
        $originalLocale = app()->getLocale();
        try {
            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $actor->forceFill(['locale' => $locale])->saveQuietly();

                foreach ([$floors[1]['public_id'], (string) Str::uuid()] as $unavailable) {
                    $this->actingAs($actor->fresh())->putJson($floorUrl($unavailable), [])
                        ->assertNotFound();
                    $this->actingAs($actor->fresh())->putJson($floorUrl($unavailable), [
                        'name_en' => ['invalid'],
                    ])->assertNotFound();
                    // A valid payload must not authorize a sibling or missing route floor.
                    $this->actingAs($actor->fresh())->putJson($floorUrl($unavailable), [
                        'name_en' => 'Unauthorized Floor',
                        'name_ar' => 'دور غير مصرح',
                        'sort_order' => 2,
                        'is_active' => 1,
                    ])->assertNotFound();
                }

                foreach ([$tables[1]['public_id'], (string) Str::uuid()] as $unavailable) {
                    $this->actingAs($actor->fresh())->putJson($tableUrl($unavailable), [])
                        ->assertNotFound();
                    $this->actingAs($actor->fresh())->putJson($tableUrl($unavailable), [
                        'capacity' => ['invalid'],
                    ])->assertNotFound();
                    // The same scoped 404 applies to well-formed table mutations.
                    $this->actingAs($actor->fresh())->putJson($tableUrl($unavailable), [
                        'floor_public_id' => $floors[0]['public_id'],
                        'label' => 'Unauthorized Table',
                        'capacity' => 4,
                        'position_x' => 125,
                        'position_y' => 135,
                        'visual_width' => 160,
                        'visual_height' => 120,
                        'shape' => 'rectangle',
                        'is_active' => 1,
                    ])->assertNotFound();
                }

                $this->actingAs($actor->fresh())->putJson($floorUrl($floors[0]['public_id']), [])
                    ->assertUnprocessable()->assertJsonValidationErrors(['name_en', 'name_ar']);
                $this->actingAs($actor->fresh())->putJson($tableUrl($tables[0]['public_id']), [])
                    ->assertUnprocessable()->assertJsonValidationErrors(['label', 'capacity']);
            }
        } finally {
            app()->setLocale($originalLocale);
        }

        $this->assertDatabaseHas('smart_ordering_floor_areas', [
            'id' => $floors[1]['id'], 'name_en' => 'Original Floor',
        ]);
        $this->assertDatabaseHas('smart_ordering_tables', [
            'id' => $tables[1]['id'], 'label' => 'Original Table', 'position_x' => 60,
        ]);

        $this->actingAs($actor->fresh())->put($floorUrl($floors[0]['public_id']), [
            'name_en' => 'Updated Floor', 'name_ar' => 'الدور المحدث',
            'sort_order' => 1, 'is_active' => 1,
        ])->assertRedirect();
        $this->actingAs($actor->fresh())->put($tableUrl($tables[0]['public_id']), [
            'floor_public_id' => $floors[0]['public_id'],
            'label' => 'Updated Table', 'capacity' => 4,
            'position_x' => 125, 'position_y' => 135,
            'visual_width' => 160, 'visual_height' => 120,
            'shape' => 'rectangle', 'is_active' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('smart_ordering_floor_areas', [
            'id' => $floors[0]['id'], 'name_en' => 'Updated Floor',
        ]);
        $this->assertDatabaseHas('smart_ordering_tables', [
            'id' => $tables[0]['id'], 'label' => 'Updated Table', 'position_x' => 125,
        ]);
        $this->assertDatabaseHas('smart_ordering_floor_areas', [
            'id' => $floors[1]['id'], 'name_en' => 'Original Floor',
        ]);
        $this->assertDatabaseHas('smart_ordering_tables', [
            'id' => $tables[1]['id'], 'label' => 'Original Table', 'position_x' => 60,
        ]);
        // Table combination has TWO scoped identifiers: the route primary
        // and the user-supplied member. The member must be checked before
        // unrelated note validation, even when it names another branch.
        $memberPublicId = (string) Str::uuid();
        $memberId = (int) DB::table('smart_ordering_tables')->insertGetId([
            'public_id' => $memberPublicId,
            'branch_id' => $ownedBranch->getKey(),
            'floor_area_id' => $floors[0]['id'],
            'label' => 'Owned Member Table',
            'capacity' => 2,
            'position_x' => 140,
            'position_y' => 140,
            'visual_width' => 160,
            'visual_height' => 120,
            'shape' => 'rectangle',
            'qr_reference' => (string) Str::uuid(),
            'token_hash' => hash('sha256', (string) Str::uuid()),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $combineUrl = route('admin.smart-ordering.tables.combine', [
            $ownedBranch->public_id, $tables[0]['public_id'],
        ]);
        $beforeCombinationCount = DB::table('smart_ordering_table_combinations')->count();
        $beforeAuditCount = DB::table('audit_logs')->count();
        $originalLocale = app()->getLocale();

        try {
            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $actor->forceFill(['locale' => $locale])->saveQuietly();

                foreach ([$tables[1]['public_id'], (string) Str::uuid()] as $deniedMember) {
                    foreach ([
                        ['other_table_public_id' => $deniedMember],
                        ['other_table_public_id' => $deniedMember, 'note' => ['bad' => 'nested']],
                        ['other_table_public_id' => $deniedMember, 'note' => 'unauthorized combine'],
                    ] as $payload) {
                        $this->actingAs($actor->fresh())
                            ->postJson($combineUrl, $payload)
                            ->assertNotFound();
                    }
                }

                foreach ([
                    [],
                    ['other_table_public_id' => 'not-a-uuid', 'note' => ['bad']],
                    ['other_table_public_id' => $memberPublicId, 'note' => ['bad']],
                ] as $invalidPayload) {
                    $this->actingAs($actor->fresh())
                        ->postJson($combineUrl, $invalidPayload)
                        ->assertUnprocessable();
                }

                // Existing route-primary protection still wins over bad
                // member/note fields before attempting any combination.
                $this->actingAs($actor->fresh())
                    ->postJson(route('admin.smart-ordering.tables.combine', [
                        $ownedBranch->public_id, $tables[1]['public_id'],
                    ]), ['other_table_public_id' => $memberPublicId, 'note' => ['bad']])
                    ->assertNotFound();
            }
        } finally {
            app()->setLocale($originalLocale);
        }

        $this->assertSame($beforeCombinationCount, DB::table('smart_ordering_table_combinations')->count());
        $this->assertSame($beforeAuditCount, DB::table('audit_logs')->count());

        $this->actingAs($actor->fresh())->post($combineUrl, [
            'other_table_public_id' => $memberPublicId,
            'note' => 'Authorized family seating',
        ])->assertRedirect();

        $this->assertDatabaseHas('smart_ordering_table_combinations', [
            'branch_id' => $ownedBranch->getKey(),
            'primary_table_id' => $tables[0]['id'],
            'member_table_id' => $memberId,
            'note' => 'Authorized family seating',
        ]);
        $this->assertSame($beforeCombinationCount + 1, DB::table('smart_ordering_table_combinations')->count());
        $this->assertSame($beforeAuditCount + 1, DB::table('audit_logs')->count());
        $this->assertSame(0, DB::table('smart_ordering_table_combinations')
            ->where('branch_id', $siblingBranch->getKey())->count());
    }
}
