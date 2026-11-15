<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

/**
 * P258: ordinary WMS warehouse transfers must never silently accept a
 * van-loading target and misrepresent warehouse stock as loaded van stock.
 */
final class V2VanLoadingAuthorizationContractTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        // Test-only readiness grant for the actual module version.
        config()->set(
            'enterprise_module_readiness.certified_versions.inventory_wms',
            app(ModuleRegistry::class)->manifest('inventory_wms')->version,
        );
    }

    public function test_warehouse_transfer_actions_reject_a_van_target_without_creating_stock_or_audit_events(): void
    {
        $storeId = (int) app(WholesalePrincipal::class)->storeId();
        $this->enableEnterpriseWmsForStore($storeId);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $payload = [
            'store_id' => $storeId,
            'source_warehouse_id' => 1,
            'target_warehouse_id' => 2,
            'product_id' => 1,
            'quantity' => 2,
            'request_key' => 'P258-NOT-A-VAN-LOAD',
        ];

        // Even an explicit null/zero must not turn an ordinary WMS transfer
        // into a purported Van load. Reject the supplied field, not only
        // positive integer IDs, across both supported user locales.
        foreach (['en', 'ar'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            foreach ([987654, null, 0, 'forged-van'] as $forgedVanTarget) {
                $this->actingAs($actor->fresh())
                    ->post(route('admin.erp.wms.transfers.store'), [
                        ...$payload,
                        'van_id' => $forgedVanTarget,
                    ])
                    ->assertRedirect()
                    ->assertSessionHasErrors(['van_id']);

                foreach (['dispatch', 'receive'] as $transition) {
                    $this->actingAs($actor->fresh())
                        ->post(route('admin.erp.wms.transfers.'.$transition, 999999), [
                            'store_id' => $storeId,
                            'van_id' => $forgedVanTarget,
                        ])
                        ->assertRedirect()
                        ->assertSessionHasErrors(['van_id']);
                }
            }
        }

        $this->assertSame(0, DB::table('enterprise_warehouse_transfers')->count());
        $this->assertSame(0, DB::table('enterprise_warehouse_transfer_lines')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'like', 'enterprise.warehouse_transfer.%')->count());
    }
}
