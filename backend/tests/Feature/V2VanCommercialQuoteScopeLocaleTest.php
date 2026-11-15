<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2VanCommercialQuoteScopeLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_scope_rejections_are_bilingual_actor_scoped_and_do_not_write(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $actor->roles()->attach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail());

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'V2-3888-QUOTE-'.$actor->id]);
        $registry->assign($actor, $van, [
            'representative_user_id' => $actor->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        Sanctum::actingAs($actor, ['app:van']);

        $ownStore = $this->wholesaleStore('V2-3888-QUOTE-OWN');
        $otherStore = $this->wholesaleStore('V2-3888-QUOTE-OTHER');
        $foreignStore = $this->wholesaleStore('V2-3888-QUOTE-FOREIGN');

        foreach ([$ownStore, $otherStore] as $storeId) {
            VanVisit::query()->create([
                'actor_user_id' => $actor->id,
                'customer_type' => 'b2b',
                'customer_id' => 999991,
                'store_id' => $storeId,
                'status' => 'planned',
            ]);
        }

        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 999992,
            'store_id' => $ownStore,
            'status' => 'planned',
        ]);

        $before = $this->writeCounts();

        foreach ([
            'ar' => [
                'multiple' => 'يجب تحديد المتجر عندما يكون العميل ضمن أكثر من نطاق زيارة مكلّف به للفان.',
                'unmapped' => 'العميل المحدد غير مرتبط بسجل العملاء التجاري المعتمد.',
                'accept' => 'en',
            ],
            'en' => [
                'multiple' => 'Store is required when the customer is in more than one assigned Van scope.',
                'unmapped' => 'The selected customer is not linked to the canonical commercial customer domain.',
                'accept' => 'ar',
            ],
        ] as $locale => $expected) {
            $actor->forceFill(['locale' => $locale])->saveQuietly();
            app()->setLocale($expected['accept']);

            $body = [
                'product_id' => 1,
                'selling_unit_code' => 'EA',
                'quantity' => 1,
            ];

            $this->withHeader('Accept-Language', $expected['accept'])
                ->postJson('/api/v1/van/customers/b2b/999991/commercial/quote', $body)
                ->assertUnprocessable()
                ->assertJsonPath('errors.store_id.0', $expected['multiple']);

            // An explicitly selected assigned store resolves the ambiguity,
            // but an unmapped legacy customer must still fail before quoting.
            $this->postJson('/api/v1/van/customers/b2b/999991/commercial/quote', [
                ...$body,
                'store_id' => $ownStore,
            ])
                ->assertUnprocessable()
                ->assertJsonPath('errors.customer_id.0', $expected['unmapped']);

            // A single assigned store is resolved without a caller-supplied
            // store ID; this path reaches the separate legacy-mapping guard.
            $this->postJson('/api/v1/van/customers/b2b/999992/commercial/quote', $body)
                ->assertUnprocessable()
                ->assertJsonPath('errors.customer_id.0', $expected['unmapped']);

            // Localization must not open a route to an unrelated B2B store,
            // even when the actor submits the store ID explicitly.
            $this->postJson('/api/v1/van/customers/b2b/999991/commercial/quote', [
                ...$body,
                'store_id' => $foreignStore,
            ])->assertNotFound();

            $this->assertSame($before, $this->writeCounts());
        }
    }

    private function wholesaleStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{orders:int,payments:int,audit_logs:int,stock_movements:int,van_visits:int} */
    private function writeCounts(): array
    {
        return [
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
            'stock_movements' => DB::table('stock_movements')->count(),
            'van_visits' => DB::table('van_visits')->count(),
        ];
    }
}
