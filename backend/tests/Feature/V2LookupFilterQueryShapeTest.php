<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2LookupFilterQueryShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
    }

    public function test_super_admin_valid_filters_remain_available_in_arabic_and_english(): void
    {
        $actor = $this->globalActor();
        $storeId = $this->store('V2-4050-GLOBAL');
        $before = $this->recordCounts();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())->get(route('admin.lookups.index', [
                'type' => 'units',
                'store_id' => $storeId,
                'scope' => 'store',
                'status' => 'inactive',
                'q' => 'Sample%_!',
            ]))->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertViewHas('type', 'units')
                ->assertViewHas('filters', static fn (array $filters): bool => $filters['q'] === 'Sample%_!'
                    && $filters['scope'] === 'store'
                    && $filters['status'] === 'inactive'
                    && $filters['store_id'] === $storeId);
        }

        $this->assertSame($before, $this->recordCounts());
    }

    public function test_nested_search_scope_and_status_are_404_without_mutation(): void
    {
        $actor = $this->globalActor();
        $before = $this->recordCounts();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            foreach ([
                ['q' => ['product']],
                ['q' => ['nested' => ['product']]],
                ['scope' => ['store']],
                ['status' => ['active']],
            ] as $query) {
                $this->actingAs($actor->fresh())
                    ->get('/admin/lookups?'.http_build_query(['type' => 'brands'] + $query))
                    ->assertNotFound();
            }
        }

        $this->assertSame($before, $this->recordCounts());
    }

    public function test_explicit_noncanonical_store_selectors_are_404_for_global_and_retail_actors(): void
    {
        $storeId = $this->store('V2-4050-RETAIL');
        $global = $this->globalActor();
        $retail = $this->retailActor($storeId);
        $before = $this->recordCounts();

        foreach ([$global, $retail] as $actor) {
            foreach ([
                ['store_id' => ['1']],
                ['store_id' => ['nested' => ['1']]],
                ['store_id' => ''],
                ['store_id' => 'invalid'],
                ['store_id' => '1e2'],
                ['store_id' => '1.0'],
                ['store_id' => '01'],
                ['store_id' => '-1'],
                ['store_id' => '999999999999999999999999999999999999'],
            ] as $query) {
                $this->actingAs($actor)->get('/admin/lookups?'.http_build_query($query))
                    ->assertNotFound();
            }
        }

        // Explicit zero retains the previous "no selected store" behavior.
        $this->actingAs($global)->get('/admin/lookups?store_id=0')
            ->assertOk()->assertViewHas('filters', static fn (array $filters): bool => $filters['store_id'] === null);
        $this->actingAs($retail)->get('/admin/lookups?store_id=0')
            ->assertOk()->assertViewHas('currentStoreId', $storeId);

        $this->assertSame($before, $this->recordCounts());
    }

    public function test_retail_selected_store_is_preserved_and_foreign_store_remains_404(): void
    {
        $storeA = $this->store('V2-4050-STORE-A');
        $storeB = $this->store('V2-4050-STORE-B');
        $actor = $this->retailActor($storeA);
        $before = $this->recordCounts();

        $this->actingAs($actor)->get(route('admin.lookups.index', [
            'type' => 'brands',
            'store_id' => $storeA,
            'q' => 'Local%',
            'status' => 'active',
        ]))->assertOk()
            ->assertViewHas('currentStoreId', $storeA)
            ->assertViewHas('filters', static fn (array $filters): bool => $filters['store_id'] === $storeA
                && $filters['q'] === 'Local%'
                && $filters['status'] === 'active');

        $this->actingAs($actor)->get('/admin/lookups?store_id='.$storeB)
            ->assertNotFound();
        $this->actingAs($actor)->get('/admin/lookups')
            ->assertOk()->assertViewHas('currentStoreId', $storeA);

        $this->assertSame($before, $this->recordCounts());
    }

    public function test_existing_unknown_scalar_filters_and_route_permissions_do_not_change(): void
    {
        $actor = $this->globalActor();

        $this->actingAs($actor)->get('/admin/lookups?type=old-tab&scope=old-scope&status=old-status')
            ->assertOk()
            ->assertViewHas('type', 'brands')
            ->assertViewHas('filters', static fn (array $filters): bool => $filters['scope'] === 'old-scope'
                && $filters['status'] === 'old-status');

        $unprivileged = User::factory()->create(['is_active' => true]);
        $this->actingAs($unprivileged)->get('/admin/lookups?type=units')->assertForbidden();
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailActor(int $storeId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $actor->roles()->attach($role);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function globalActor(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }

    /** @return array<int,int> */
    private function recordCounts(): array
    {
        return [
            DB::table('brands')->count(),
            DB::table('units')->count(),
            DB::table('audit_logs')->count(),
        ];
    }
}
