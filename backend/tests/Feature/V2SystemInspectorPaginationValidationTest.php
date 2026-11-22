<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemInspectorEvent;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SystemInspectorPaginationValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspector_pagination_rejects_invalid_values_after_platform_scope_ar_en(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $url = route('admin.inspector.index');
        $originalLocale = app()->getLocale();

        try {
            foreach (['ar', 'en'] as $locale) {
                $admin->forceFill(['locale' => $locale])->saveQuietly();
                app()->setLocale($locale);

                $eventCount = SystemInspectorEvent::query()->count();
                $auditCount = DB::table('audit_logs')->count();

                foreach ([
                    '?page[]=2',
                    '?page=invalid',
                    '?page=0',
                    '?page=-1',
                    '?page=1.5',
                    '?page=999999999999999999999999',
                ] as $invalid) {
                    $this->actingAs($admin->fresh())->getJson($url.$invalid)
                        ->assertUnprocessable();
                    $this->assertSame($eventCount, SystemInspectorEvent::query()->count());
                    $this->assertSame($auditCount, DB::table('audit_logs')->count());
                }

                // Existing nested filters remain a 404, before page validation.
                $this->actingAs($admin->fresh())->getJson($url.'?source[]=bad&page=invalid')
                    ->assertNotFound();

                // Permission enforcement precedes parsing and diagnostics.
                $this->actingAs($roleless)->getJson($url.'?page=invalid')
                    ->assertForbidden();

                foreach (['', '?page=1', '?page=2', '?page='] as $valid) {
                    $this->actingAs($admin->fresh())->get($url.$valid)
                        ->assertOk();
                }
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }
}
