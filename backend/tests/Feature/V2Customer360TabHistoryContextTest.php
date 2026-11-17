<?php

namespace Tests\Feature;

use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * G009 Customer 360: Back/Forward restores an existing record tab only;
 * hashes cannot authorize a foreign tenant or fetch a new record.
 */
final class V2Customer360TabHistoryContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_bilingual_scoped_customer_record_renders_history_restoration_for_existing_tabs(): void
    {
        $store = $this->store('C360-HISTORY-OWNED');
        $customer = $this->customer($store, 'c360-history-owned@example.test');
        $actor = $this->retailAdmin($store);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($actor->fresh())
                ->get(route('admin.customer-360.show', [
                    'platformCustomer' => $customer->id,
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
                ->assertSee('data-c360-tab="service"', false)
                ->assertDontSee('data-c360-tab="history"', false);

            $html = (string) $response->getContent();

            // Both browser-history entry creation and restoration are required;
            // hash-only replacement leaves Back/Forward unable to reselect tabs.
            $this->assertStringContainsString("window.history.pushState(window.history.state, '', '#'+name)", $html);
            $this->assertStringNotContainsString('history.replaceState(', $html);
            $this->assertStringContainsString("window.addEventListener('popstate', restoreTabFromUrl)", $html);
            $this->assertStringContainsString("window.addEventListener('hashchange', restoreTabFromUrl)", $html);
            $this->assertStringContainsString("const available = tabs.some((tab) => tab.dataset.c360Tab === requested);", $html);
            $this->assertStringContainsString("activate(available ? requested : 'finance');", $html);
            $this->assertStringContainsString("window.location.hash !== '#'+name", $html);

            // The existing statement/date and finance subflow are not replaced
            // by invented History/Credit routes or new client-side data sources.
            $response->assertViewHas('statementFilters', fn (mixed $filters): bool => is_array($filters)
                && ($filters['statement_from'] ?? null) === '2026-01-01'
                && ($filters['statement_to'] ?? null) === '2026-02-28');
            $this->assertStringContainsString('data-c360-panel="orders"', $html);
            $this->assertStringContainsString('data-c360-panel="invoices"', $html);
            $this->assertStringContainsString('data-c360-panel="statement"', $html);
        }
    }

    public function test_forged_record_tab_hash_and_page_query_do_not_cross_store_scope(): void
    {
        $storeA = $this->store('C360-HISTORY-STORE-A');
        $storeB = $this->store('C360-HISTORY-STORE-B');
        $owned = $this->customer($storeA, 'c360-history-a@example.test');
        $foreign = $this->customer($storeB, 'c360-history-b@example.test');
        $actor = $this->retailAdmin($storeA);

        $this->actingAs($actor)
            ->get(route('admin.customer-360.show', [
                'platformCustomer' => $owned->id,
                'orders_page' => 2,
            ], false).'#orders')
            ->assertOk();

        $this->actingAs($actor)
            ->get(route('admin.customer-360.show', [
                'platformCustomer' => $foreign->id,
                'invoices_page' => 2,
                'statement_from' => '2026-01-01',
            ], false).'#statement')
            ->assertNotFound();
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
