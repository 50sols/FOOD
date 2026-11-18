<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * G009 #3974: a one-row finance child-tab rail is read-only navigation.
 * Controller-validated scope, custody/ledger state and review POSTs own the data.
 */
final class V2FieldOperationsFinanceTabsPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_four_existing_finance_tabs_preserve_context_on_authorized_routes_in_ar_and_en(): void
    {
        $admin = $this->superAdmin();
        $before = $this->financialCounts();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            foreach ([
                'field-operations' => '/admin/field-operations/finance',
                'b2b-finance' => '/admin/b2b/finance?finance_tab=operations',
            ] as $routeKind => $baseUrl) {
                foreach (['wallets', 'collections', 'remittances', 'reconciliation'] as $tab) {
                    $joiner = str_contains($baseUrl, '?') ? '&' : '?';
                    $url = $baseUrl.$joiner.http_build_query([
                        'ops_tab' => $tab,
                        'ops_q' => 'KWD test',
                        'ops_status' => 'pending',
                        'ops_per_page' => 10,
                        'ops_page' => 2,
                    ]);
                    $response = $this->actingAs($admin->fresh())->get($url)
                        ->assertOk()
                        ->assertSee('dir="'.$direction.'"', false)
                        ->assertSee('data-field-finance-tabs', false);

                    $html = $response->getContent();
                    $this->assertSame(1, preg_match(
                        '/<nav\b[^>]*data-field-finance-tabs[^>]*>(.*?)<\/nav>/s',
                        $html,
                        $railMatches,
                    ), $routeKind.' should expose one shared finance tab rail.');
                    $rail = $railMatches[1];
                    preg_match_all('/<a\b[^>]*href="([^"]+)"[^>]*>/', $rail, $links, PREG_SET_ORDER);
                    $this->assertCount(4, $links);
                    $this->assertSame(1, substr_count($rail, 'aria-current="page"'));
                    $this->assertStringNotContainsString('<form', $rail);
                    $this->assertStringNotContainsString('onclick=', $rail);

                    $seenTabs = [];
                    foreach ($links as $link) {
                        $href = html_entity_decode($link[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
                        $seenTabs[] = $query['ops_tab'] ?? null;
                        $this->assertSame('KWD test', $query['ops_q'] ?? null);
                        $this->assertSame('pending', $query['ops_status'] ?? null);
                        $this->assertSame('10', (string) ($query['ops_per_page'] ?? ''));
                        $this->assertArrayNotHasKey('ops_page', $query);
                        if ($routeKind === 'b2b-finance') {
                            $this->assertSame('operations', $query['finance_tab'] ?? null);
                        }
                    }
                    $this->assertSame(['wallets', 'collections', 'remittances', 'reconciliation'], $seenTabs);
                    $this->assertSame(1, preg_match(
                        '/<a\b[^>]*href="[^"]*ops_tab='.preg_quote($tab, '/').'[^"]*"[^>]*aria-current="page"/',
                        $rail,
                    ));
                    $this->assertSame($before, $this->financialCounts(), 'GET tab navigation must not post, approve, remit or reconcile.');
                }
            }
        }
    }

    public function test_finance_tab_one_row_layout_and_server_owned_review_actions_are_preserved(): void
    {
        $source = file_get_contents(resource_path('views/admin/_field-operations-finance.blade.php'));
        $this->assertIsString($source);

        $this->assertStringContainsString('data-field-finance-tabs', $source);
        $this->assertStringContainsString('.fieldops-finance-tabs {', $source);
        $this->assertStringContainsString('flex-wrap: nowrap;', $source);
        $this->assertStringContainsString('overflow-x: auto;', $source);
        $this->assertStringContainsString('overscroll-behavior-inline: contain;', $source);
        $this->assertStringContainsString('min-height: 44px;', $source);
        $this->assertStringContainsString('white-space: nowrap;', $source);
        $this->assertStringContainsString(':focus-visible', $source);
        $this->assertStringContainsString('aria-current="page"', $source);

        // Financial data and approval are still rendered as one original tab
        // per server response; no client-only or hidden-tab finance POST.
        $this->assertStringContainsString('route($opsReviewRouteName', $source);
        $this->assertStringContainsString('@csrf', $source);
        $this->assertStringContainsString("@if(\$opsTab==='wallets')", $source);
        $this->assertStringContainsString("@elseif(\$opsTab==='collections')", $source);
        $this->assertStringContainsString("@if(\$opsTab==='reconciliation')", $source);
        $this->assertStringContainsString("request()->except('ops_page', 'ops_tab')", $source);
    }

    public function test_unprivileged_actor_cannot_open_finance_route_or_approve_a_hidden_tab(): void
    {
        $unprivileged = User::query()->create([
            'name' => 'No finance permissions',
            'email' => 'v2-field-finance-unprivileged@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $before = $this->financialCounts();

        foreach (['wallets', 'collections', 'remittances', 'reconciliation'] as $tab) {
            $this->actingAs($unprivileged)->get(
                '/admin/field-operations/finance?ops_tab='.$tab,
            )->assertForbidden();
        }
        $this->assertSame($before, $this->financialCounts());
    }

    private function superAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Field finance super admin',
            'email' => 'v2-field-finance-admin@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function financialCounts(): array
    {
        return [
            'collection_accounts' => DB::table('collection_accounts')->count(),
            'collection_transactions' => DB::table('collection_transactions')->count(),
            'remittances' => DB::table('remittances')->count(),
            'custody_entries' => DB::table('custody_ledger_entries')->count(),
        ];
    }
}
