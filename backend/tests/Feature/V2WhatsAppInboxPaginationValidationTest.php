<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2WhatsAppInboxPaginationValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_whatsapp_inbox_rejects_malformed_page_after_authorization_in_ar_en(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => true,
            'capabilities' => ['agent_inbox' => true],
            'test_numbers' => [],
        ])->save();

        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $url = route('admin.whatsapp-inbox.index');
        $originalLocale = app()->getLocale();

        try {
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                $admin->forceFill(['locale' => $locale])->saveQuietly();
                app()->setLocale($locale);

                $conversationCount = DB::table('whats_app_conversations')->count();
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
                    $this->assertSame($conversationCount, DB::table('whats_app_conversations')->count());
                    $this->assertSame($auditCount, DB::table('audit_logs')->count());
                }

                $this->actingAs($roleless)->getJson($url.'?page=invalid')
                    ->assertForbidden();

                foreach (['', '?page=1', '?page=2', '?page='] as $valid) {
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
