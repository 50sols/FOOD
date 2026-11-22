<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2RetailWorkspaceDualPaginationValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_rows_and_assignments_page_fail_closed_after_store_module_authorization_in_ar_en(): void
    {
        $ownedStore = $this->retailStore('V2-P4236-OWNED');
        $foreignStore = $this->retailStore('V2-P4236-FOREIGN');
        $actor = $this->storeManager('retail-pagination-allowed@example.test', $ownedStore);
        $otherStoreActor = $this->storeManager('retail-pagination-foreign@example.test', $foreignStore);
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $before = [
            'stores' => DB::table('stores')->count(),
            'orders' => DB::table('orders')->count(),
            'products' => DB::table('store_products')->count(),
            'invoices' => DB::table('invoices')->count(),
            'inventories' => DB::table('inventories')->count(),
        ];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->saveQuietly();

            foreach (['products', 'drivers'] as $module) {
                $root = route('admin.b2c.module', ['module' => $module, 'store_id' => $ownedStore]);

                foreach ([
                    '?rows_page[]=2',
                    '?rows_page[other]=1',
                    '?rows_page=invalid',
                    '?rows_page=0',
                    '?rows_page=-1',
                    '?rows_page=1.5',
                    '?rows_page=99999999999999999999999999999',
                    '?assignments_page[]=2',
                    '?assignments_page[other]=1',
                    '?assignments_page=invalid',
                    '?assignments_page=0',
                    '?assignments_page=-1',
                    '?assignments_page=1.5',
                    '?assignments_page=99999999999999999999999999999',
                    '?rows_page=1&assignments_page=invalid',
                    '?rows_page=invalid&assignments_page=1',
                ] as $selector) {
                    $this->actingAs($actor->fresh())
                        ->getJson($root.$selector)
                        ->assertUnprocessable();
                }

                // Denial of the selected Retail store occurs before
                // malformed navigation is exposed or any list is paginated.
                $this->actingAs($roleless)
                    ->getJson($root.'&rows_page=invalid')
                    ->assertForbidden();
                $this->actingAs($otherStoreActor)
                    ->getJson($root.'&assignments_page=invalid')
                    ->assertNotFound();

                foreach ([
                    '', '?rows_page=', '?assignments_page=',
                    '?rows_page=1', '?assignments_page=1',
                    '?rows_page=2&assignments_page=3',
                ] as $selector) {
                    $this->actingAs($actor->fresh())
                        ->get($root.$selector)
                        ->assertOk()
                        ->assertViewHas('storeId', $ownedStore)
                        ->assertViewHas('storeIds', [$ownedStore])
                        ->assertSee('dir="'.$direction.'"', false);
                }
            }
        }

        $this->assertSame($before['stores'], DB::table('stores')->count());
        $this->assertSame($before['orders'], DB::table('orders')->count());
        $this->assertSame($before['products'], DB::table('store_products')->count());
        $this->assertSame($before['invoices'], DB::table('invoices')->count());
        $this->assertSame($before['inventories'], DB::table('inventories')->count());
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeManager(string $email, int $storeId): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true, 'locale' => 'en']);
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
