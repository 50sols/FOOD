<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VanVisitControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_actor_visit_feed_exposes_canonical_route_context_from_metadata(): void
    {
        $actor = $this->vanActor();

        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 42,
            'store_id' => 7,
            'status' => 'planned',
            'metadata' => ['route_key' => 'ROUTE-A'],
        ]);

        $this->getJson('/api/v1/van/visits')
            ->assertOk()
            ->assertJsonPath('data.0.route_key', 'ROUTE-A')
            ->assertJsonPath('data.0.status', 'planned')
            ->assertJsonPath('data.0.customer_type', 'b2b')
            ->assertJsonPath('data.0.customer_id', 42);
    }

    public function test_actor_visit_feed_falls_back_to_legacy_route_metadata_keys(): void
    {
        $actor = $this->vanActor();

        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2c',
            'customer_id' => 43,
            'store_id' => 8,
            'status' => 'planned',
            'metadata' => ['route_code' => 'ROUTE-LEGACY'],
        ]);

        $this->getJson('/api/v1/van/visits')
            ->assertOk()
            ->assertJsonPath('data.0.route_key', 'ROUTE-LEGACY');
    }


    public function test_missing_b2b_customer_validation_uses_saved_van_locale_and_does_not_write(): void
    {
        $actor = $this->vanActor();
        $arabic = 'العميل المحدد غير موجود في سجل العملاء المعتمد.';
        $english = 'The selected customer does not exist in the authoritative customer domain.';

        foreach ([
            ['ar', 'en', $arabic],
            ['en', 'ar', $english],
            ['fr', 'en', $english],
            ['fr', 'ar', $arabic],
        ] as [$savedLocale, $acceptLanguage, $expected]) {
            $actor->forceFill(['locale' => $savedLocale])->save();
            $tables = ['van_visits', 'orders', 'invoices', 'payments', 'stock_movements', 'audit_logs'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = DB::table($table)->count();
            }

            $this->withHeaders(['Accept-Language' => $acceptLanguage])
                ->postJson('/api/v1/van/visits', [
                    'customer_type' => 'b2b',
                    'customer_id' => 99999999,
                    'idempotency_key' => 'missing-customer-'.$savedLocale.'-'.$acceptLanguage,
                ])
                ->assertUnprocessable()
                ->assertJsonPath('errors.customer_id.0', $expected);

            foreach ($tables as $table) {
                $this->assertSame($before[$table], DB::table($table)->count(), $table.' was mutated on a rejected Van visit');
            }
        }
    }

    public function test_existing_b2b_customer_visit_creation_still_succeeds(): void
    {
        $this->vanActor();

        $legacy = Customer::query()->create([
            'name' => 'Authorized Visit Buyer',
            'type' => 'b2b',
            'email' => 'authorized-visit-buyer@example.test',
        ]);
        $customer = B2bCustomer::query()->create([
            'legacy_customer_id' => $legacy->getKey(),
            'name' => 'Authorized Visit Buyer',
            'email' => 'authorized-visit-buyer@example.test',
        ]);

        $this->postJson('/api/v1/van/visits', [
            'customer_type' => 'b2b',
            'customer_id' => (int) $customer->getKey(),
            'idempotency_key' => 'valid-van-b2b-visit-localization-3816',
        ])
            ->assertCreated()
            ->assertJsonPath('customer_type', 'b2b')
            ->assertJsonPath('customer_id', (int) $customer->getKey());

        $this->assertSame(1, VanVisit::query()->where('customer_id', $customer->getKey())->count());
    }

    private function vanActor(): User
    {
        $this->seed(CoreReferenceSeeder::class);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail());

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'VISIT-VAN-'.$actor->id]);
        $registry->assign($actor, $van, [
            'representative_user_id' => $actor->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        Sanctum::actingAs($actor, ['app:van']);

        return $actor;
    }
}
