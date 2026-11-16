<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseCashbox;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2TreasuryMasterTabsContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_bilingual_tab_links_preserve_authorized_store_filters_and_do_not_open_other_editor(): void
    {
        $store = $this->store('V2-TREASURY-TAB-A');
        $actor = $this->financeActor($store);
        $this->bank($store, 'B-CONTEXT');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $response = $this->actingAs($actor->fresh())->get(route('admin.treasury.master.index', [
                'tab' => 'bank_accounts',
                'store_id' => $store->id,
                'q' => 'B-CONTEXT',
                'status' => 'active',
                'per_page' => 50,
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false);

            $html = $response->getContent();
            $this->assertStringContainsString('aria-current="page"', $html);
            $this->assertStringContainsString('treasury-tab-rail', $html);
            $this->assertStringNotContainsString('id="cashbox-form-title"', $html);
            $this->assertStringNotContainsString('id="bank-form-title"', $html);

            preg_match_all('/href="([^"]+)"/', $html, $matches);
            $matchingTabs = [];
            foreach ($matches[1] as $encoded) {
                $url = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (! str_contains($url, '/admin/treasury/master-data')) {
                    continue;
                }
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                if (in_array($query['tab'] ?? null, ['cashboxes', 'bank_accounts'], true)
                    && ! isset($query['create']) && ! isset($query['edit_cashbox']) && ! isset($query['edit_bank_account'])
                    && ($query['q'] ?? null) === 'B-CONTEXT') {
                    $matchingTabs[(string) $query['tab']] = $query;
                }
            }

            $this->assertSameCanonicalizing(['cashboxes', 'bank_accounts'], array_keys($matchingTabs));
            foreach ($matchingTabs as $query) {
                $this->assertSame((string) $store->id, (string) $query['store_id']);
                $this->assertSame('active', $query['status']);
                $this->assertSame('50', (string) $query['per_page']);
            }

            $form = $this->actingAs($actor->fresh())->get(route('admin.treasury.master.index', [
                'tab' => 'bank_accounts',
                'store_id' => $store->id,
                'create' => 'bank_account',
            ]))->assertOk();
            $form->assertSee('id="bank-form-title"', false);
            $form->assertDontSee('id="cashbox-form-title"', false);
            $form->assertSee('value="'.$store->id.'" selected', false);
        }
    }

    public function test_conflicting_hidden_tab_editor_queries_are_denied_and_do_not_mutate_or_expose_account(): void
    {
        $store = $this->store('V2-TREASURY-TAB-B');
        $foreign = $this->store('V2-TREASURY-TAB-C');
        $actor = $this->financeActor($store);
        $bank = $this->bank($store, 'B-HIDDEN');
        $otherBank = $this->bank($foreign, 'B-FOREIGN');
        $cashbox = $this->cashbox($store, 'C-HIDDEN');

        $before = [
            DB::table('enterprise_bank_accounts')->count(),
            DB::table('enterprise_cashboxes')->count(),
            DB::table('audit_logs')->count(),
        ];

        foreach ([
            ['tab' => 'cashboxes', 'create' => 'bank_account'],
            ['tab' => 'bank_accounts', 'create' => 'cashbox'],
            ['tab' => 'cashboxes', 'edit_bank_account' => $bank->id],
            ['tab' => 'bank_accounts', 'edit_cashbox' => $cashbox->id],
            ['tab' => 'cashboxes', 'edit_cashbox' => $cashbox->id, 'edit_bank_account' => $bank->id],
            ['tab' => 'bank_accounts', 'create' => 'bank_account', 'edit_bank_account' => $bank->id],
            ['tab' => 'bank_accounts', 'edit_bank_account' => $otherBank->id],
        ] as $params) {
            $this->actingAs($actor)->get(route('admin.treasury.master.index', $params))
                ->assertNotFound();
        }

        $this->actingAs($actor)->get(route('admin.treasury.master.index', [
            'tab' => 'bank_accounts', 'store_id' => $foreign->id,
        ]))->assertNotFound();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)->get(route('admin.treasury.master.index', [
            'tab' => 'bank_accounts', 'edit_bank_account' => $bank->id,
        ]))->assertForbidden();

        $this->assertSame($before, [
            DB::table('enterprise_bank_accounts')->count(),
            DB::table('enterprise_cashboxes')->count(),
            DB::table('audit_logs')->count(),
        ]);
    }

    public function test_bank_validation_returns_to_active_form_and_success_preserves_store(): void
    {
        $store = $this->store('V2-TREASURY-TAB-D');
        $actor = $this->financeActor($store);
        $createUrl = route('admin.treasury.master.index', [
            'tab' => 'bank_accounts',
            'store_id' => $store->id,
            'create' => 'bank_account',
        ]);
        $valid = [
            'store_id' => $store->id,
            'code' => 'B-NEW',
            'name' => 'Treasury test account',
            'bank_name' => 'Test bank',
            'account_name' => 'Treasury holder',
            'account_reference' => 'KW00TESTREFERENCE1234',
            'operator_visibility' => 'store',
            'approval_visibility' => 'store',
            'is_active' => '1',
        ];

        $this->actingAs($actor)->from($createUrl)
            ->post(route('admin.treasury.bank-accounts.store'), [...$valid, 'code' => ''])
            ->assertRedirect($createUrl)->assertSessionHasErrors('code');

        $this->actingAs($actor)->get($createUrl)->assertOk()
            ->assertSee('id="bank-form-title"', false)
            ->assertDontSee('id="cashbox-form-title"', false);

        $this->actingAs($actor)->post(route('admin.treasury.bank-accounts.store'), $valid)
            ->assertRedirect(route('admin.treasury.master.index', [
                'tab' => 'bank_accounts', 'store_id' => $store->id,
            ]));

        $this->assertDatabaseHas('enterprise_bank_accounts', [
            'store_id' => $store->id,
            'code' => 'B-NEW',
        ]);

        $cashbox = [
            'store_id' => $store->id,
            'code' => 'C-NEW',
            'name' => 'Treasury till',
            'operator_visibility' => 'store',
            'approval_visibility' => 'store',
            'is_active' => '1',
        ];

        $this->actingAs($actor)->post(route('admin.treasury.cashboxes.store'), $cashbox)
            ->assertRedirect(route('admin.treasury.master.index', [
                'tab' => 'cashboxes', 'store_id' => $store->id,
            ]));
        $this->assertDatabaseHas('enterprise_cashboxes', [
            'store_id' => $store->id,
            'code' => 'C-NEW',
        ]);

        $bankRecord = EnterpriseBankAccount::query()->where('store_id', $store->id)->where('code', 'B-NEW')->firstOrFail();
        $cashRecord = EnterpriseCashbox::query()->where('store_id', $store->id)->where('code', 'C-NEW')->firstOrFail();
        $this->actingAs($actor)->patch(route('admin.treasury.bank-accounts.update', ['bankAccount' => $bankRecord->id]), [
            ...$valid, 'name' => 'Updated account', 'account_reference' => '',
        ])->assertRedirect(route('admin.treasury.master.index', [
            'tab' => 'bank_accounts', 'store_id' => $store->id,
        ]));
        $this->actingAs($actor)->patch(route('admin.treasury.cashboxes.update', ['cashbox' => $cashRecord->id]), [
            ...$cashbox, 'name' => 'Updated cashbox',
        ])->assertRedirect(route('admin.treasury.master.index', [
            'tab' => 'cashboxes', 'store_id' => $store->id,
        ]));
        $this->assertDatabaseHas('enterprise_bank_accounts', [
            'id' => $bankRecord->id, 'store_id' => $store->id, 'name' => 'Updated account',
        ]);
        $this->assertDatabaseHas('enterprise_cashboxes', [
            'id' => $cashRecord->id, 'store_id' => $store->id, 'name' => 'Updated cashbox',
        ]);
    }

    public function test_invalid_array_tab_does_not_render_an_editor_or_mutate_master_data(): void
    {
        $store = $this->store('V2-TREASURY-TAB-E');
        $actor = $this->financeActor($store);
        $before = DB::table('audit_logs')->count();

        $this->actingAs($actor)->from(route('admin.treasury.master.index'))
            ->get(route('admin.treasury.master.index').'?tab[]=bank_accounts')
            ->assertRedirect()->assertSessionHasErrors('tab');

        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('enterprise_bank_accounts', 0);
    }

    private function store(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function financeActor(Store $store): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function cashbox(Store $store, string $code): EnterpriseCashbox
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseCashbox::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => $code,
            'name' => 'Treasury cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }

    private function bank(Store $store, string $code): EnterpriseBankAccount
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        $reference = 'KW00SYNTH'.$code;

        return EnterpriseBankAccount::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => $code,
            'name' => 'Treasury bank account',
            'bank_name' => 'Test Bank',
            'account_name' => 'Treasury holder',
            'account_reference' => $reference,
            'account_reference_last4' => substr($reference, -4),
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }
}
