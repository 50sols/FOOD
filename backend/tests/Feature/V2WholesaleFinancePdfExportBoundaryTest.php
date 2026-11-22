<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P031: A PDF download is a real finance HTTP action and must enforce the
 * same trusted Wholesale scope as the existing Excel export.
 */
final class V2WholesaleFinancePdfExportBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_finance_pdf_download_preserves_channel_roles_audit_and_no_writes_in_both_locales(): void
    {
        $retailStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'P031-FINANCE-PDF-RETAIL',
            'name' => 'P031 Finance PDF Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $wholesaleActor = $this->actor('B2B_ADMIN');
        $retailActor = $this->actor('B2C_STORE_ADMIN', $retailStore);
        $rolelessActor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $counts = [
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'invoices' => DB::table('invoices')->count(),
            'assignments' => DB::table('driver_assignments')->count(),
        ];

        foreach (['ar', 'en'] as $locale) {
            $wholesaleActor->forceFill(['locale' => $locale])->saveQuietly();
            $url = route('admin.b2b.module', [
                'module' => 'finance',
                'export' => 'pdf',
                'channel' => 'b2c',
                'store_id' => $retailStore,
            ], false);

            $before = DB::table('audit_logs')->where('event', 'b2b.finance.exported')->count();

            // The B2B controller computes the trusted principal store rather
            // than accepting a foreign Retail store/channel from query input.
            $response = $this->actingAs($wholesaleActor->fresh())->get($url);
            $response->assertOk()
                ->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
            $this->assertStringContainsString(
                'attachment;',
                (string) $response->headers->get('Content-Disposition'),
            );
            $this->assertStringContainsString(
                'no-store',
                (string) $response->headers->get('Cache-Control'),
            );
            $this->assertSame(
                $before + 1,
                DB::table('audit_logs')->where('event', 'b2b.finance.exported')->count(),
            );

            $this->actingAs($retailActor->fresh())->get($url)->assertForbidden();
            $this->actingAs($rolelessActor)->get($url)->assertForbidden();
            $this->assertSame(
                $before + 1,
                DB::table('audit_logs')->where('event', 'b2b.finance.exported')->count(),
                'Denied PDF downloads must not be recorded as successful exports',
            );
        }

        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' must not change');
        }
    }

    private function actor(string $roleCode, ?int $storeId = null): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

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
