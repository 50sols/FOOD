<?php

namespace Tests\Feature;

use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * G009 Customer 360: existing scoped page tabs must survive each independent
 * paginator navigation. A URL hash selects a panel; it grants no data access.
 */
final class V2Customer360TabPaginationContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_bilingual_customer_record_paginators_preserve_all_query_context_and_their_own_tab(): void
    {
        $store = $this->store('TAB-C360-OWNED');
        $platformCustomer = $this->customer($store, 'tab-c360-owned@example.test');
        $actor = $this->retailAdmin($store);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($actor->fresh())
                ->get(route('admin.customer-360.show', [
                    'platformCustomer' => $platformCustomer->id,
                    'orders_page' => 2,
                    'invoices_page' => 3,
                    'statement_from' => '2026-01-01',
                    'statement_to' => '2026-02-28',
                ], false))
                ->assertOk()
                ->assertViewIs('admin.customer-360-show')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-c360-tab="orders"', false)
                ->assertSee('data-c360-tab="invoices"', false)
                ->assertSee('data-c360-tab="statement"', false)
                ->assertSee('data-c360-tab="finance"', false)
                ->assertDontSee('data-c360-tab="history"', false);

            $expectedPath = route('admin.customer-360.show', [
                'platformCustomer' => $platformCustomer->id,
            ], false);

            $response->assertViewHas('orders', function (mixed $orders) use ($expectedPath): bool {
                return $orders instanceof LengthAwarePaginator
                    && $this->hasScopedPaginationContext($orders->url(4), $expectedPath, [
                        'orders_page' => '4',
                        'invoices_page' => '3',
                    ], 'orders');
            });

            $response->assertViewHas('invoices', function (mixed $invoices) use ($expectedPath): bool {
                return $invoices instanceof LengthAwarePaginator
                    && $this->hasScopedPaginationContext($invoices->url(4), $expectedPath, [
                        'orders_page' => '2',
                        'invoices_page' => '4',
                    ], 'invoices');
            });
        }
    }

    public function test_tab_hash_or_forged_pagination_does_not_bypass_retail_customer_scope(): void
    {
        $ownedStore = $this->store('TAB-C360-ALLOW');
        $foreignStore = $this->store('TAB-C360-DENY');
        $owned = $this->customer($ownedStore, 'tab-c360-allow@example.test');
        $foreign = $this->customer($foreignStore, 'tab-c360-deny@example.test');
        $actor = $this->retailAdmin($ownedStore);

        $this->actingAs($actor)
            ->get(route('admin.customer-360.show', [
                'platformCustomer' => $owned->id,
                'orders_page' => 2,
                'invoices_page' => 2,
            ], false))
            ->assertOk();

        // URL fragments are client-side only: explicit page query parameters
        // cannot make another store's customer visible.
        $this->actingAs($actor)
            ->get(route('admin.customer-360.show', [
                'platformCustomer' => $foreign->id,
                'orders_page' => 2,
                'invoices_page' => 2,
            ], false).'#invoices')
            ->assertNotFound();
    }

    /**
     * @param  array<string, string>  $pages
     */
    private function hasScopedPaginationContext(
        string $url,
        string $expectedPath,
        array $pages,
        string $tab,
    ): bool {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return parse_url($url, PHP_URL_PATH) === $expectedPath
            && parse_url($url, PHP_URL_FRAGMENT) === $tab
            && (string) ($query['orders_page'] ?? '') === $pages['orders_page']
            && (string) ($query['invoices_page'] ?? '') === $pages['invoices_page']
            && ($query['statement_from'] ?? null) === '2026-01-01'
            && ($query['statement_to'] ?? null) === '2026-02-28';
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(int $storeId, string $email): PlatformCustomer
    {
        $user = app(PlatformCustomerService::class)->register([
            'name' => 'Scoped Customer',
            'email' => $email,
            'phone' => '+201000000000',
            'password' => 'Password123!',
            'locale' => 'en',
            'store_id' => $storeId,
        ]);

        return PlatformCustomer::query()->where('user_id', $user->id)->firstOrFail();
    }

    private function retailAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Scoped Retail Admin',
            'email' => "tab-c360-admin-{$storeId}@example.test",
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
