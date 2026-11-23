<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P031: The existing Wholesale finance export is a protected HTTP route,
 * not a public file link or a substitute for permission and channel checks.
 */
final class V2WholesaleFinanceExportBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_finance_export_requires_wholesale_authority_in_both_locales(): void
    {
        $wholesale = $this->actor('B2B_ADMIN');
        $retailStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'P031-FINANCE-RETAIL',
            'name' => 'P031 Finance Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $retail = $this->actor('B2C_STORE_ADMIN', $retailStoreId);
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $before = [
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'invoices' => DB::table('invoices')->count(),
        ];

        foreach (['ar', 'en'] as $locale) {
            $wholesale->forceFill(['locale' => $locale])->saveQuietly();
            $url = route('admin.b2b.module', ['module' => 'finance', 'export' => 'xlsx'], false);
            $auditsBefore = DB::table('audit_logs')->where('event', 'b2b.finance.exported')->count();

            $response = $this->actingAs($wholesale->fresh())->get($url);
            $response->assertOk();
            $this->assertStringContainsString(
                'attachment;',
                (string) $response->headers->get('Content-Disposition'),
            );
            $this->assertStringContainsString(
                'no-store',
                (string) $response->headers->get('Cache-Control'),
            );
            $this->assertSame(
                $auditsBefore + 1,
                DB::table('audit_logs')->where('event', 'b2b.finance.exported')->count(),
            );

            // A Retail-only principal may not export Wholesale finance even
            // when supplying the Wholesale path and a chosen Retail store.
            $this->actingAs($retail->fresh())
                ->get(route('admin.b2b.module', [
                    'module' => 'finance',
                    'export' => 'xlsx',
                    'store_id' => $retailStoreId,
                ], false))
                ->assertForbidden();

            $this->actingAs($roleless)
                ->get($url)
                ->assertForbidden();

            $this->assertSame(
                $auditsBefore + 1,
                DB::table('audit_logs')->where('event', 'b2b.finance.exported')->count(),
            );
        }

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' must be unchanged');
        }
    }

    private function actor(string $code, ?int $storeId = null): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', $code)->firstOrFail();

        if ($storeId === null) {
            $actor->roles()->attach($role);
        } else {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->id,
                'store_id' => $storeId,
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $actor;
    }
}
