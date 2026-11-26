<?php

namespace Tests\Feature;

use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseCashbox;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2TreasuryMasterPaginationValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_treasury_master_pages_fail_closed_after_finance_and_store_authorization_in_ar_en(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $retailTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $ownedStore = Store::query()->create([
            'store_type_id' => $retailTypeId,
            'code' => 'V2-TREASURY-PAGE-OWN',
            'name' => 'Treasury Page Owned',
            'is_active' => true,
        ]);
        $foreignStore = Store::query()->create([
            'store_type_id' => $retailTypeId,
            'code' => 'V2-TREASURY-PAGE-OTHER',
            'name' => 'Treasury Page Foreign',
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $scopedActor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $scopedActor->id,
            'store_id' => $ownedStore->id,
            'role_id' => Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $url = route('admin.treasury.master.index');
        $originalLocale = app()->getLocale();

        try {
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                $admin->forceFill(['locale' => $locale])->saveQuietly();
                app()->setLocale($locale);

                $countsBefore = [
                    'cashboxes' => EnterpriseCashbox::query()->count(),
                    'banks' => EnterpriseBankAccount::query()->count(),
                    'stores' => Store::query()->count(),
                    'audit' => DB::table('audit_logs')->count(),
                ];

                foreach (['cashboxes_page', 'bank_accounts_page'] as $selector) {
                    foreach (['[]=2', '=invalid', '=0', '=-1', '=1.5', '=999999999999999999999999'] as $invalid) {
                        $this->actingAs($admin->fresh())->getJson($url.'?'.$selector.$invalid)
                            ->assertUnprocessable();
                    }
                }

                $this->assertSame($countsBefore['cashboxes'], EnterpriseCashbox::query()->count());
                $this->assertSame($countsBefore['banks'], EnterpriseBankAccount::query()->count());
                $this->assertSame($countsBefore['stores'], Store::query()->count());
                $this->assertSame($countsBefore['audit'], DB::table('audit_logs')->count());

                // Permission and tenant identity precede an unrelated bad page parameter.
                $this->actingAs($roleless)->getJson($url.'?cashboxes_page=0')
                    ->assertForbidden();
                $this->actingAs($scopedActor)->getJson(
                    $url.'?store_id='.$foreignStore->id.'&bank_accounts_page=invalid'
                )->assertNotFound();

                foreach ([
                    '',
                    '?cashboxes_page=1',
                    '?bank_accounts_page=2',
                    '?cashboxes_page=2&bank_accounts_page=1',
                    '?cashboxes_page=&bank_accounts_page=',
                ] as $valid) {
                    $this->actingAs($admin->fresh())->get($url.$valid)
                        ->assertOk()
                        ->assertSee('dir="'.$direction.'"', false);
                }
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }
}
