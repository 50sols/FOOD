<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2ReportSelectorQueryShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
    }

    public function test_authorized_scalar_report_selection_stays_bilingual_and_read_only(): void
    {
        $admin = $this->superAdmin();
        $before = $this->counts();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            foreach (['orders', 'products'] as $report) {
                $this->actingAs($admin->fresh())
                    ->get('/admin/reports?report='.$report)
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false);
            }

            $this->actingAs($admin->fresh())
                ->get('/admin/reports')
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_nested_report_selector_is_404_before_report_execution_or_html_rendering(): void
    {
        $admin = $this->superAdmin();
        $before = $this->counts();

        foreach (['ar', 'en'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            foreach ([
                'report%5B%5D=orders',
                'report%5B0%5D=products',
                'report%5Bnested%5D=orders',
                'report%5Bnested%5D%5B0%5D=products',
            ] as $malformedQuery) {
                $this->actingAs($admin->fresh())
                    ->get('/admin/reports?'.$malformedQuery)
                    ->assertNotFound();
            }
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_unauthorized_actor_stays_forbidden_before_malformed_filter_check(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $before = $this->counts();

        $this->actingAs($actor)
            ->get('/admin/reports?report=orders')
            ->assertForbidden();

        $this->actingAs($actor)
            ->get('/admin/reports?report%5B0%5D=orders')
            ->assertForbidden();

        $this->assertSame($before, $this->counts());
    }

    /** @return array<int, int> */
    private function counts(): array
    {
        return [
            DB::table('orders')->count(),
            DB::table('products')->count(),
            DB::table('audit_logs')->count(),
        ];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
