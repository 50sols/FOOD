<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CommercialFeatureFlags;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class V2ProfitabilityRuleLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
        app(CommercialFeatureFlags::class)->persist([
            CommercialFeatureFlags::COMMERCIAL_RULES => true,
        ]);
    }

    public function test_catalog_view_only_role_cannot_read_confidential_profitability_revisions(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $save = $this->saveRoute($store, $product);
        $this->actingAs($maker)->put($save, $this->proposal((string) Str::uuid(), '67.89'))
            ->assertSessionHasNoErrors();

        // A catalog reader may browse saleable products, but the role has no
        // commercial policy write, financial approval or profitability access.
        // B2B_ADMIN is a registered wholesale global-role template; narrow
        // its actual grants to read-only catalog access for this test.
        // An arbitrary custom global role is intentionally not auto-scoped.
        $viewerRole = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $viewerRole->permissions()->sync(Permission::query()
            ->whereIn('code', ['stores.view', 'catalog.view'])
            ->pluck('id')
            ->all());
        $viewer = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $viewer->roles()->attach($viewerRole);

        $page = route('admin.commercial.sales-control', [
            'store_id' => $store, 'product' => $product,
        ]);
        foreach (['ar', 'en'] as $locale) {
            $viewer->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $this->actingAs($viewer->fresh())->get($page)->assertOk()
                ->assertDontSee('67.89%')
                ->assertDontSee('data-v2-p229-profitability', false);
        }

        // Unauthorized mutations conceal the B2B store scope with 404.
        $this->actingAs($viewer)->put($save, $this->proposal((string) Str::uuid(), '67.89'))
            ->assertNotFound();
        $this->actingAs($this->user('SUPER_ADMIN'))->get($page)->assertOk()
            ->assertSee('67.89%')
            ->assertSee('data-v2-p229-profitability', false);
    }

    public function test_scoped_proposal_uses_exact_basis_points_and_request_idempotency_without_activating_prices(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $key = (string) Str::uuid();
        $payload = $this->proposal($key, '12.34');
        $route = $this->saveRoute($store, $product);

        $this->actingAs($maker)->put($route, $payload)
            ->assertRedirect(route('admin.commercial.sales-control', ['store_id' => $store, 'product' => $product]))
            ->assertSessionHasNoErrors();

        $rule = DB::table('commercial_profitability_rule_revisions')->sole();
        $this->assertSame('DRAFT', $rule->status);
        $this->assertSame(1234, (int) $rule->minimum_margin_basis_points);
        $this->assertSame(1, (int) $rule->revision);
        $this->assertSame((int) $maker->id, (int) $rule->proposed_by);
        $this->assertNull($rule->reviewed_by);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'commercial.profitability_rule.proposed')->count());
        $this->assertSame(0, DB::table('product_commercial_policies')->where('product_id', $product)->count());

        $this->actingAs($maker)->put($route, $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('commercial_profitability_rule_revisions')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'commercial.profitability_rule.proposed')->count());

        $this->actingAs($maker)->put($route, $this->proposal($key, '12.35'))
            ->assertSessionHasErrors('request_id');
        $this->assertSame(1, DB::table('commercial_profitability_rule_revisions')->count());

        $this->actingAs($maker)->put($route, $this->proposal((string) Str::uuid(), '12.35'))
            ->assertSessionHasNoErrors();
        $rules = DB::table('commercial_profitability_rule_revisions')->orderBy('revision')->get();
        $this->assertSame([1, 2], $rules->pluck('revision')->map(fn ($value) => (int) $value)->all());
        $this->assertSame([1234, 1235], $rules->pluck('minimum_margin_basis_points')->map(fn ($value) => (int) $value)->all());
        $this->assertSame(['DRAFT', 'DRAFT'], $rules->pluck('status')->all());
    }

    public function test_another_authorized_maker_cannot_replay_a_different_makers_request_id(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $otherMaker = $this->user('B2B_ADMIN');
        $key = (string) Str::uuid();
        $payload = $this->proposal($key, '12.34');
        $route = $this->saveRoute($store, $product);

        $this->actingAs($maker)->put($route, $payload)->assertSessionHasNoErrors();
        $this->actingAs($otherMaker)->put($route, $payload)->assertSessionHasErrors('request_id');

        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'request_id' => $key,
            'proposed_by' => $maker->id,
            'status' => 'DRAFT',
        ]);
        $this->assertSame(1, DB::table('commercial_profitability_rule_revisions')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'commercial.profitability_rule.proposed')->count());
    }

    public function test_independent_finance_checker_can_review_without_catalog_manage_but_cannot_edit_sales_policy(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $route = $this->saveRoute($store, $product);
        $this->actingAs($maker)->put($route, $this->proposal((string) Str::uuid(), '12.34'))
            ->assertSessionHasNoErrors();
        $revision = (int) DB::table('commercial_profitability_rule_revisions')->value('id');

        // Checker receives read-only catalog discovery plus two independent
        // approval grants. Catalog authoring is deliberately withheld.
        $checkerRole = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $grants = Permission::query()
            ->whereIn('code', ['catalog.view', 'orders.approve', 'finance.approve'])
            ->pluck('id')->all();
        $this->assertCount(3, $grants);
        $checkerRole->permissions()->sync($grants);
        $checker = $this->user('B2B_ADMIN');
        $this->assertFalse($checker->hasPermission('catalog.manage'));

        $this->actingAs($checker)->get(route('admin.commercial.sales-control', [
            'store_id' => $store, 'product' => $product,
        ]))->assertOk()->assertSee('12.34%');
        $this->actingAs($checker)->put($route, [
            'profitability_action' => 'review',
            'revision_id' => $revision,
            'decision' => 'review',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $revision,
            'status' => 'REVIEWED_INACTIVE',
            'reviewed_by' => $checker->id,
        ]);

        // Review eligibility never confers a proposal or normal sales-policy
        // mutation permission, even on the same canonical PUT endpoint.
        $this->actingAs($checker)->put($route, $this->proposal((string) Str::uuid(), '13.00'))
            ->assertNotFound();
        $this->actingAs($checker)->put($route, ['status' => 'OPEN'])
            ->assertNotFound();

        // Both approval permissions remain mandatory; an authorized catalog
        // reader cannot turn one approval into a two-person financial decision.
        foreach ([
            ['catalog.view', 'orders.approve'],
            ['catalog.view', 'finance.approve'],
        ] as $reducedGrants) {
            $checkerRole->permissions()->sync(Permission::query()
                ->whereIn('code', $reducedGrants)->pluck('id')->all());
            $this->actingAs($checker->fresh())->put($route, [
                'profitability_action' => 'review',
                'revision_id' => $revision,
                'decision' => 'review',
            ])->assertForbidden();
        }
        $this->assertSame(1, DB::table('commercial_profitability_rule_revisions')->count());
    }

    public function test_independent_checker_review_remains_inactive_and_is_idempotent(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $checker = $this->user('SUPER_ADMIN');
        $route = $this->saveRoute($store, $product);
        $this->actingAs($maker)->put($route, $this->proposal((string) Str::uuid(), '99.99'))
            ->assertSessionHasNoErrors();
        $revision = (int) DB::table('commercial_profitability_rule_revisions')->value('id');
        $review = [
            'profitability_action' => 'review',
            'revision_id' => $revision,
            'decision' => 'review',
        ];

        $this->actingAs($maker)->put($route, $review)->assertForbidden();
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', ['id' => $revision, 'status' => 'DRAFT']);

        $this->actingAs($checker)->put($route, $review)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $revision,
            'status' => 'REVIEWED_INACTIVE',
            'reviewed_by' => $checker->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'commercial.profitability_rule.reviewed')->count());

        $this->actingAs($checker)->put($route, $review)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'commercial.profitability_rule.reviewed')->count());

        $this->actingAs($checker)->put($route, [...$review, 'decision' => 'reject'])
            ->assertSessionHasErrors('revision_id');
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $revision, 'status' => 'REVIEWED_INACTIVE',
        ]);

        $this->actingAs($checker)->get(route('admin.commercial.sales-control', [
            'store_id' => $store, 'product' => $product,
        ]))->assertOk()
            ->assertSee(__('commercial.profitability.statuses.reviewed_inactive'))
            ->assertSee(__('commercial.profitability.inactive_explanation'));

        // Finance + Catalog authority, even SUPER_ADMIN, never defeats
        // distinct-person maker/checker separation for a new revision.
        $this->actingAs($checker)->put($route, $this->proposal((string) Str::uuid(), '8.88'))
            ->assertSessionHasNoErrors();
        $selfRevision = (int) DB::table('commercial_profitability_rule_revisions')
            ->orderByDesc('revision')->value('id');
        $this->actingAs($checker)->put($route, [
            'profitability_action' => 'review',
            'revision_id' => $selfRevision,
            'decision' => 'review',
        ])->assertForbidden();
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $selfRevision, 'status' => 'DRAFT', 'reviewed_by' => null,
        ]);
    }

    public function test_independent_review_prevents_overlapping_inactive_margin_windows_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            [$maker, $store, $product] = $this->fixture();
            $checker = $this->user('SUPER_ADMIN');
            $route = $this->saveRoute($store, $product);

            // Multiple proposals can coexist before commercial review; only
            // one overlapping period may receive reviewed-inactive status.
            $this->actingAs($maker)->put(
                $route, $this->proposal((string) Str::uuid(), '15.00'),
            )->assertSessionHasNoErrors();
            $this->actingAs($maker)->put(
                $route, $this->proposal((string) Str::uuid(), '25.00'),
            )->assertSessionHasNoErrors();

            $ids = DB::table('commercial_profitability_rule_revisions')
                ->where('store_id', $store)->where('product_id', $product)
                ->orderBy('revision')->pluck('id')->all();
            $review = static fn (int $id, string $decision): array => [
                'profitability_action' => 'review',
                'revision_id' => $id,
                'decision' => $decision,
            ];

            $checker->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $this->actingAs($checker->fresh())->put(
                $route, $review((int) $ids[0], 'review'),
            )->assertSessionHasNoErrors();

            $this->actingAs($checker->fresh())->put(
                $route, $review((int) $ids[1], 'review'),
            )->assertSessionHasErrors([
                'revision_id' => __('commercial.profitability.overlapping_window', [], $locale),
            ]);
            $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
                'id' => $ids[1], 'status' => 'DRAFT', 'reviewed_by' => null,
            ]);

            // Rejection of the conflicting draft is still allowed.
            $this->actingAs($checker->fresh())->put(
                $route, $review((int) $ids[1], 'reject'),
            )->assertSessionHasNoErrors();

            // An adjacent period starting on the next day is not overlapping.
            $next = [
                ...$this->proposal((string) Str::uuid(), '30.00'),
                'effective_from' => now()->addMonth()->addDay()->toDateString(),
                'effective_until' => now()->addMonths(2)->toDateString(),
            ];
            $this->actingAs($maker)->put($route, $next)
                ->assertSessionHasNoErrors();
            $third = (int) DB::table('commercial_profitability_rule_revisions')
                ->where('store_id', $store)->where('product_id', $product)
                ->orderByDesc('revision')->value('id');
            $this->actingAs($checker->fresh())->put(
                $route, $review($third, 'review'),
            )->assertSessionHasNoErrors();

            $this->assertSame(2, DB::table('commercial_profitability_rule_revisions')
                ->where('store_id', $store)->where('product_id', $product)
                ->where('status', 'REVIEWED_INACTIVE')->count());
            $this->assertSame(1, DB::table('commercial_profitability_rule_revisions')
                ->where('store_id', $store)->where('product_id', $product)
                ->where('status', 'REJECTED')->count());
            $this->assertSame(3, DB::table('audit_logs')
                ->where('event', 'commercial.profitability_rule.reviewed')
                ->count() - (($locale === 'ar') ? 3 : 0));
            $this->assertSame(0, DB::table('product_commercial_policies')
                ->where('product_id', $product)->count());
        }
    }

    public function test_bilingual_review_and_rejection_states_are_visible_and_non_operational(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $checker = $this->user('SUPER_ADMIN');
        $route = $this->saveRoute($store, $product);
        $this->actingAs($maker)->put($route, $this->proposal((string) Str::uuid(), '0.01'))
            ->assertSessionHasNoErrors();
        $id = (int) DB::table('commercial_profitability_rule_revisions')->value('id');
        $this->actingAs($checker)->put($route, [
            'profitability_action' => 'review', 'revision_id' => $id, 'decision' => 'reject',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $id, 'minimum_margin_basis_points' => 1, 'status' => 'REJECTED',
        ]);

        foreach (['ar', 'en'] as $locale) {
            $checker->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $this->actingAs($checker->fresh())->get(route('admin.commercial.sales-control', [
                'store_id' => $store, 'product' => $product,
            ]))->assertOk()
                ->assertSee(__('commercial.profitability.title', [], $locale))
                ->assertSee(__('commercial.profitability.statuses.rejected', [], $locale))
                ->assertSee(__('commercial.profitability.inactive_explanation', [], $locale));
        }
    }

    public function test_untrusted_fields_and_invalid_percentages_are_rejected_before_any_financial_or_audit_write(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $route = $this->saveRoute($store, $product);
        $cases = [
            [$this->proposal((string) Str::uuid(), '100.01'), 'minimum_margin_percent'],
            [$this->proposal((string) Str::uuid(), '12.345'), 'minimum_margin_percent'],
            [[...$this->proposal((string) Str::uuid(), '12.00'), 'stock_owner' => 'FOODEX'], 'stock_owner'],
            [[...$this->proposal((string) Str::uuid(), '12.00'), 'cost_basis' => 0], 'cost_basis'],
            [[...$this->proposal((string) Str::uuid(), '12.00'), 'profitability_action' => 'activate'], 'profitability_action'],
        ];
        foreach ($cases as [$payload, $errorField]) {
            $this->actingAs($maker)->put($route, $payload)->assertSessionHasErrors($errorField);
        }

        $this->assertSame(0, DB::table('commercial_profitability_rule_revisions')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'like', 'commercial.profitability_rule.%')->count());
    }

    public function test_store_scope_role_and_disabled_module_fail_closed(): void
    {
        [$maker, $store, $product] = $this->fixture();
        [, $otherStore, $otherProduct] = $this->fixture();
        $route = $this->saveRoute($store, $product);
        $payload = $this->proposal((string) Str::uuid(), '15');
        $roleless = User::factory()->create(['is_active' => true]);

        // B2B_ADMIN is intentionally a global Wholesale role. Its second B2B
        // store is not a foreign tenant; test the actual Retail channel fence.
        DB::table('stores')->where('id', $otherStore)->update([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
        ]);
        $this->actingAs($roleless)->put($route, $payload)->assertForbidden();
        $this->actingAs($maker)->put($this->saveRoute($otherStore, $otherProduct), $payload)->assertStatus(404);
        $this->actingAs($maker)->put($this->saveRoute($store, $otherProduct), $payload)->assertStatus(404);

        app(CommercialFeatureFlags::class)->persist([
            CommercialFeatureFlags::COMMERCIAL_RULES => false,
        ]);
        $this->actingAs($maker)->put($route, $payload)->assertForbidden();
        $this->assertSame(0, DB::table('commercial_profitability_rule_revisions')->count());
    }

    /** @return array{User,int,int} */
    private function fixture(): array
    {
        static $counter = 0;
        $counter++;
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'P229-LIFECYCLE-'.$counter,
            'name' => 'P229 Lifecycle '.$counter,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $maker = $this->user('B2B_ADMIN');
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'P229-UNIT-'.$counter, 'name' => 'Unit',
            'decimal_places' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId, 'channel' => 'b2b', 'code' => 'p229-lifecycle-'.$counter,
            'name' => 'Lifecycle Catalog', 'is_active' => true, 'is_migration_quarantine' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalogId, 'name' => 'Lifecycle', 'slug' => 'p229-lifecycle-'.$counter,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId, 'category_id' => $categoryId,
            'unit_id' => $unitId, 'sku' => 'P229-LIFECYCLE-'.$counter,
            'name' => 'Lifecycle Product', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId, 'product_id' => $productId,
            'price' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$maker, $storeId, $productId];
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }

    /** @return array<string,string> */
    private function proposal(string $requestId, string $margin): array
    {
        return [
            'profitability_action' => 'propose',
            'request_id' => $requestId,
            'minimum_margin_percent' => $margin,
            'effective_from' => now()->addDay()->toDateString(),
            'effective_until' => now()->addMonth()->toDateString(),
        ];
    }

    private function saveRoute(int $storeId, int $productId): string
    {
        return route('admin.commercial.sales-control.save', [
            'store_id' => $storeId,
            'product' => $productId,
        ]);
    }
}
