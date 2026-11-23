<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2AssistantIntelligencePaginationValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config([
            'assistant.enabled' => true,
            'assistant.read_only' => true,
            'assistant.external_ai.enabled' => false,
        ]);
    }

    public function test_independent_intelligence_pages_validate_after_permission_in_ar_en_without_writes(): void
    {
        $admin = User::query()->create([
            'name' => 'V2 Intelligence Pagination Operator',
            'email' => 'v2-intelligence-pages@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $roleless = User::query()->create([
            'name' => 'V2 Intelligence Pagination Roleless',
            'email' => 'v2-intelligence-roleless@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $url = route('admin.assistant-intelligence.index');

        $before = [
            'findings' => DB::table('assistant_intelligence_findings')->count(),
            'briefs' => DB::table('assistant_intelligence_briefs')->count(),
            'subscriptions' => DB::table('assistant_intelligence_subscriptions')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->saveQuietly();

            foreach (['findings_page', 'briefs_page'] as $selector) {
                foreach (['[]=2', '=invalid', '=0', '=-1', '=1.5', '=999999999999999999999999'] as $invalid) {
                    $this->actingAs($admin->fresh())
                        ->getJson($url.'?'.$selector.$invalid)
                        ->assertUnprocessable();
                }
            }

            foreach ([
                '',
                '?findings_page=',
                '?briefs_page=',
                '?findings_page=1',
                '?briefs_page=2',
                '?findings_page=2&briefs_page=3',
            ] as $valid) {
                $this->actingAs($admin->fresh())
                    ->get($url.$valid)
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false);
            }

            // The permission gate must run before either pagination validator.
            $this->actingAs($roleless)
                ->getJson($url.'?findings_page=0&briefs_page[]=2')
                ->assertForbidden();
        }

        config(['assistant.enabled' => false]);
        $this->actingAs($admin->fresh())
            ->getJson($url.'?findings_page=0')
            ->assertForbidden();
        config(['assistant.enabled' => true]);

        $this->assertSame($before['findings'], DB::table('assistant_intelligence_findings')->count());
        $this->assertSame($before['briefs'], DB::table('assistant_intelligence_briefs')->count());
        $this->assertSame($before['subscriptions'], DB::table('assistant_intelligence_subscriptions')->count());
        $this->assertSame($before['audit'], DB::table('audit_logs')->count());
    }
}
