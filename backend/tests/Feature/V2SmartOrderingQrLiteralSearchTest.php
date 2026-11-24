<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\SmartOrderingQrManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SmartOrderingQrLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_qr_page_selector_rejects_invalid_values_before_pagination_in_ar_en(): void
    {
        $owned = $this->store('V2-QR-PAGE-OWN', true);
        $foreign = $this->store('V2-QR-PAGE-FOREIGN', true);
        $actor = $this->manager($owned);
        $otherActor = $this->manager($foreign);
        $manager = app(SmartOrderingQrManager::class);
        $qr = $manager->create($this->payload($owned, ['label' => 'Paginated QR']))['qr'];
        $manager->create($this->payload($foreign, ['label' => 'Foreign QR']));
        $url = route('admin.smart-ordering.qr.index');
        $before = [
            DB::table('smart_ordering_qr_codes')->count(),
            DB::table('smart_ordering_qr_tokens')->count(),
            DB::table('audit_logs')->count(),
        ];
        $originalLocale = app()->getLocale();

        try {
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                $actor->forceFill(['locale' => $locale])->saveQuietly();
                app()->setLocale($locale);

                foreach ([
                    'qr_page=invalid',
                    'qr_page=0',
                    'qr_page=-1',
                    'qr_page=1.5',
                    'qr_page=9999999999999999999999999',
                    'qr_page%5B%5D=1',
                ] as $bad) {
                    $this->actingAs($actor->fresh())
                        ->getJson($url.'?store_id='.$owned.'&'.$bad)
                        ->assertUnprocessable();
                }

                foreach (['', '&qr_page=1', '&qr_page=2'] as $page) {
                    $response = $this->actingAs($actor->fresh())
                        ->get($url.'?store_id='.$owned.$page)
                        ->assertOk()
                        ->assertSee('dir="'.$direction.'"', false);
                    $this->assertSame($page === '&qr_page=2' ? 2 : 1, $response->viewData('qrs')->currentPage());
                    if ($page !== '&qr_page=2') {
                        $this->assertSame([(int) $qr->id], $response->viewData('qrs')->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all());
                    }
                }
            }
        } finally {
            app()->setLocale($originalLocale);
        }

        $this->actingAs($otherActor)->getJson($url.'?store_id='.$owned.'&qr_page=invalid')->assertNotFound();
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->actingAs($roleless)->getJson($url.'?store_id='.$owned.'&qr_page=invalid')->assertForbidden();
        $this->assertSame($before, [
            DB::table('smart_ordering_qr_codes')->count(),
            DB::table('smart_ordering_qr_tokens')->count(),
            DB::table('audit_logs')->count(),
        ]);
    }

    public function test_qr_metadata_search_is_literal_bilingual_store_scoped_and_read_only(): void
    {
        $a = $this->store('V2-QR-LIT-A', true);
        $b = $this->store('V2-QR-LIT-B', true);
        $actor = $this->manager($a);
        $manager = app(SmartOrderingQrManager::class);

        $label = $manager->create($this->payload($a, ['label' => 'شعار %_! عربية']))['qr'];
        $lookalike = $manager->create($this->payload($a, ['label' => 'شعار ABC عربية']))['qr'];
        $source = $manager->create($this->payload($a, [
            'label' => 'Source QR',
            'source' => 'SRC_!KEY',
        ]))['qr'];
        $campaign = $manager->create($this->payload($a, [
            'label' => 'Campaign QR',
            'campaign' => 'CMP%!SPECIAL',
        ]))['qr'];
        $branch = $manager->create($this->payload($a, [
            'label' => 'Branch QR',
            'branch_reference' => 'BR_!XYZ',
        ]))['qr'];
        $table = $manager->create($this->payload($a, [
            'type' => 'table',
            'label' => 'Table QR',
            'table_reference' => 'TABLE_!R',
        ]))['qr'];
        $slash = $manager->create($this->payload($a, [
            'label' => 'Slash \ Mark',
        ]))['qr'];
        $manager->create($this->payload($b, ['label' => 'شعار %_! أجنبية']));

        $initial = [
            'qr' => DB::table('smart_ordering_qr_codes')->count(),
            'tokens' => DB::table('smart_ordering_qr_tokens')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];

        foreach ([
            ['locale' => 'ar', 'q' => '%_!', 'expected' => [$label->id]],
            ['locale' => 'en', 'q' => 'SRC_!', 'expected' => [$source->id]],
            ['locale' => 'en', 'q' => 'CMP%!', 'expected' => [$campaign->id]],
            ['locale' => 'en', 'q' => 'BR_!', 'expected' => [$branch->id]],
            ['locale' => 'en', 'q' => 'TABLE_!', 'expected' => [$table->id]],
            ['locale' => 'en', 'q' => chr(92), 'expected' => [$slash->id]],
            ['locale' => 'ar', 'q' => 'عربية', 'expected' => [$label->id, $lookalike->id]],
            ['locale' => 'en', 'q' => 'missing%_!', 'expected' => []],
        ] as $case) {
            $actor->forceFill(['locale' => $case['locale']])->save();
            app()->setLocale($case['locale']);

            $response = $this->actingAs($actor->fresh())
                ->get(route('admin.smart-ordering.qr.index', [
                    'store_id' => $a,
                    'q' => $case['q'],
                ]))
                ->assertOk()
                ->assertSee('dir="'.($case['locale'] === 'ar' ? 'rtl' : 'ltr').'"', false);

            $actual = $response->viewData('qrs')->getCollection()
                ->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
            $expected = $case['expected'];
            sort($expected);
            $this->assertSame($expected, $actual, 'QR search must match literal metadata, not SQL wildcards.');
        }

        $this->actingAs($actor->fresh())
            ->get(route('admin.smart-ordering.qr.index', [
                'store_id' => $b,
                'q' => '%_!',
            ]))
            ->assertNotFound();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)
            ->get(route('admin.smart-ordering.qr.index', ['store_id' => $a, 'q' => '%_!']))
            ->assertForbidden();

        $unentitled = $this->store('V2-QR-LIT-OFF', false);
        $this->actingAs($this->manager($unentitled))
            ->get(route('admin.smart-ordering.qr.index', ['store_id' => $unentitled, 'q' => '%_!']))
            ->assertForbidden();

        $this->assertSame($initial['qr'], DB::table('smart_ordering_qr_codes')->count());
        $this->assertSame($initial['tokens'], DB::table('smart_ordering_qr_tokens')->count());
        $this->assertSame($initial['audit'], DB::table('audit_logs')->count());
    }

    /** @return array<string,mixed> */
    private function payload(int $storeId, array $overrides = []): array
    {
        return $overrides + [
            'store_id' => $storeId,
            'type' => 'generic',
            'label' => 'Default QR',
            'target_path' => '/menu',
            'source' => 'qr',
            'medium' => 'offline',
            'campaign' => null,
            'branch_reference' => null,
            'table_reference' => null,
            'promotion_id' => null,
            'valid_from' => null,
            'valid_until' => null,
            'is_enabled' => true,
        ];
    }

    private function store(string $code, bool $enabled): int
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RESTAURANT',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($enabled) {
            $this->enableRestaurantSaasForTestStore($storeId);
        }

        return $storeId;
    }

    private function manager(int $storeId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }
}
