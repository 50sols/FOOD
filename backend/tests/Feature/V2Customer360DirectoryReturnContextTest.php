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
 * G009 #4028 — return links must carry the authorized directory context,
 * never confer a tenant/store grant or change Customer 360's financial data.
 */
final class V2Customer360DirectoryReturnContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_arabic_english_directory_open_and_back_preserve_allowed_filters_and_exact_customer(): void
    {
        $storeId = $this->store('C360-RETURN-A');
        $customer = $this->customer($storeId, 'c360-return-a@example.test');
        $actor = $this->retailAdmin($storeId);
        $before = $this->businessCounts();

        // The customer's literal name includes LIKE metacharacters. The
        // existing index search is authoritative, not replaced by this PR.
        $filters = [
            'q' => '%_!',
            'origin_channel' => 'b2c',
            'active' => '1',
            'origin_store_id' => $storeId,
            'date_from' => '2020-01-01',
            'date_to' => '2100-01-01',
        ];
        $listParams = [
            'list_q' => '%_!',
            'list_origin_channel' => 'b2c',
            'list_active' => '1',
            'list_origin_store_id' => $storeId,
            'list_date_from' => '2020-01-01',
            'list_date_to' => '2100-01-01',
        ];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $index = $this->actingAs($actor->fresh())->get(route('admin.customer-360.index', $filters))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $recordUrl = route('admin.customer-360.show', array_merge(
                ['platformCustomer' => $customer->id],
                $listParams,
            ));
            $this->assertStringContainsString(
                'href="'.$recordUrl.'"',
                html_entity_decode((string) $index->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            );

            $detail = $this->actingAs($actor->fresh())->get($recordUrl)
                ->assertOk()
                ->assertViewHas('customer', fn ($row): bool => (int) $row->id === (int) $customer->id)
                ->assertViewHas('backToListUrl', route('admin.customer-360.index', $filters));

            $html = html_entity_decode((string) $detail->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertSame(3, substr_count(
                $html,
                'href="'.route('admin.customer-360.index', $filters).'"',
            ));

            foreach (['finance', 'identity', 'service', 'addresses', 'stores', 'orders', 'invoices', 'statement'] as $tab) {
                $this->assertStringContainsString('data-c360-tab="'.$tab.'"', $html);
            }
            $this->assertStringNotContainsString('data-c360-tab="history"', $html);
            $this->assertStringNotContainsString('data-c360-tab="credit"', $html);
        }

        $this->assertSame($before, $this->businessCounts(),
            'Directory-to-record GET links must never write ledger, orders or invoice state.');
    }

    public function test_pagination_and_statement_deep_link_retain_record_and_return_context_without_an_open_redirect(): void
    {
        $storeId = $this->store('C360-RETURN-PAGE');
        $customer = $this->customer($storeId, 'c360-return-page@example.test');
        $actor = $this->retailAdmin($storeId);
        $before = $this->businessCounts();

        $response = $this->actingAs($actor)->get(route('admin.customer-360.show', [
            'platformCustomer' => $customer->id,
            'list_page' => 3,
            'list_origin_store_id' => $storeId,
            'list_q' => 'Scoped',
            'orders_page' => 2,
            'invoices_page' => 3,
            'statement_from' => '2026-01-01',
            'statement_to' => '2026-02-28',
            'return_url' => 'https://untrusted.example.test/foreign',
        ]))->assertOk()
            ->assertViewHas('backToListUrl', route('admin.customer-360.index', [
                'q' => 'Scoped',
                'origin_store_id' => $storeId,
                'page' => 3,
            ]))
            ->assertViewHas('statementFilters', fn (array $v): bool => ($v['statement_from'] ?? null) === '2026-01-01'
                && ($v['statement_to'] ?? null) === '2026-02-28');

        $html = html_entity_decode((string) $response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringNotContainsString('untrusted.example.test', $html);
        $this->assertStringContainsString('data-c360-tab="orders"', $html);
        $this->assertStringContainsString('data-c360-tab="invoices"', $html);
        $this->assertStringContainsString('data-c360-tab="statement"', $html);
        $this->assertStringContainsString("window.history.pushState(window.history.state, '', '#'+name)", $html);
        $this->assertStringContainsString('name="{{ $contextName }}" value="{{ $contextValue }}"', $this->statementFormSource());

        $this->assertSame($before, $this->businessCounts());
    }

    public function test_forged_foreign_store_and_foreign_customer_are_not_exposed_by_return_context(): void
    {
        $storeA = $this->store('C360-RETURN-OWN');
        $storeB = $this->store('C360-RETURN-FOREIGN');
        $owned = $this->customer($storeA, 'c360-return-owned@example.test');
        $foreign = $this->customer($storeB, 'c360-return-foreign@example.test');
        $actor = $this->retailAdmin($storeA);
        $before = $this->businessCounts();

        $this->actingAs($actor)->get(route('admin.customer-360.show', [
            'platformCustomer' => $owned->id,
            'list_origin_store_id' => $storeB,
            'list_page' => 2,
        ]))->assertNotFound();

        $this->actingAs($actor)->get(route('admin.customer-360.show', [
            'platformCustomer' => $foreign->id,
            'list_origin_store_id' => $storeA,
            'orders_page' => 2,
        ]))->assertNotFound();

        $this->actingAs($actor)->get(route('admin.customer-360.index', [
            'origin_store_id' => $storeB,
        ]))->assertNotFound();

        $this->assertSame($before, $this->businessCounts());
    }

    public function test_view_only_list_context_does_not_modify_statement_export_or_financial_post_actions(): void
    {
        $indexSource = file_get_contents(resource_path('views/admin/customer-360-index.blade.php'));
        $showSource = file_get_contents(resource_path('views/admin/customer-360-show.blade.php'));

        $this->assertIsString($indexSource);
        $this->assertIsString($showSource);
        $this->assertStringContainsString(
            "array_merge(['platformCustomer'=>\$row['id']], \$listReturnContext)",
            $indexSource,
        );
        $this->assertStringContainsString(
            '@foreach($listReturnContext as $contextName => $contextValue)',
            $showSource,
        );
        $this->assertStringContainsString(
            "route('admin.customer-360.statement.export'",
            $showSource,
        );
        $this->assertStringContainsString(
            "'format'=>'pdf','locale'=>app()->getLocale()",
            $showSource,
        );
        $this->assertStringContainsString(
            "'format'=>'xlsx','locale'=>app()->getLocale()",
            $showSource,
        );
        $this->assertStringContainsString(
            "route('admin.customer-360.credit-limit.update'",
            $showSource,
        );
        $this->assertStringContainsString(
            "route('admin.customer-360.finance-entries.store'",
            $showSource,
        );
    }

    private function statementFormSource(): string
    {
        // This is a structural assertion only: a Retail-only customer cannot
        // view a wholesale statement. The original server controls the panel.
        $source = file_get_contents(resource_path('views/admin/customer-360-show.blade.php'));
        $this->assertIsString($source);

        return $source;
    }

    /** @return list<int> */
    private function businessCounts(): array
    {
        return [
            DB::table('orders')->count(),
            DB::table('invoices')->count(),
            DB::table('audit_logs')->count(),
        ];
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
            'name' => 'Scoped %_! Customer',
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
            'name' => 'Scoped Return Admin',
            'email' => "c360-return-admin-{$storeId}@example.test",
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
