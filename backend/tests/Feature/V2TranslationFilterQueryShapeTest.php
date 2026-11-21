<?php

namespace Tests\Feature;

use App\Models\AppTranslation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2TranslationFilterQueryShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
    }

    public function test_invalid_array_filters_fail_closed_before_syncing_catalog_or_writing_audit(): void
    {
        $actor = $this->superAdmin();
        $before = $this->counts();

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            foreach ([
                ['q' => ['invalid']],
                ['surface' => ['admin']],
                ['q' => ['nested' => ['invalid']]],
                ['surface' => ['nested' => ['admin']]],
            ] as $malformed) {
                $this->actingAs($actor->fresh())
                    ->get('/admin/settings/translations?'.http_build_query($malformed))
                    ->assertNotFound();

                // Invalid GET must not call TranslationCatalog::syncDefaults().
                $this->assertSame($before, $this->counts());
            }
        }
    }

    public function test_valid_scalar_filters_preserve_literal_search_surface_and_bilingual_layout(): void
    {
        $actor = $this->superAdmin();
        $target = AppTranslation::query()->create([
            'key' => 'v2_4054_special%_',
            'surface' => 'test_4054',
            'ar' => 'تجربة %_',
            'en' => 'V2 4054 literal %_',
        ]);
        $lookalike = AppTranslation::query()->create([
            'key' => 'v2_4054_specialAB',
            'surface' => 'test_4054',
            'ar' => 'تجربة مختلفة',
            'en' => 'V2 4054 literal AB',
        ]);
        $other = AppTranslation::query()->create([
            'key' => 'v2_4054_other',
            'surface' => 'other_4054',
            'ar' => 'خارج النطاق',
            'en' => 'Other surface',
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($actor->fresh())
                ->get('/admin/settings/translations?'.http_build_query([
                    'q' => 'v2_4054_special%_',
                    'surface' => 'test_4054',
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($target->key)
                ->assertDontSee($lookalike->key)
                ->assertDontSee($other->key);

            $this->actingAs($actor->fresh())
                ->get('/admin/settings/translations?surface=unrecognized_4054')
                ->assertOk()
                ->assertDontSee($target->key);

            $this->actingAs($actor->fresh())
                ->get('/admin/settings/translations?surface=test_4054')
                ->assertOk()
                ->assertSee($target->key)
                ->assertSee($lookalike->key)
                ->assertDontSee($other->key);
        }
    }

    public function test_empty_search_and_surface_query_values_preserve_filter_form_behavior(): void
    {
        $actor = $this->superAdmin();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            // Browsers submit empty form fields; middleware may convert them to null.
            $this->actingAs($actor->fresh())
                ->get('/admin/settings/translations?q=&surface=')
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor->fresh())
                ->get('/admin/settings/translations?q=')
                ->assertOk();
        }
    }

    public function test_actor_without_translation_permission_cannot_read_valid_or_malformed_filters(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $before = $this->counts();

        $this->actingAs($actor)->get('/admin/settings/translations?q=valid')->assertForbidden();
        $this->actingAs($actor)->get('/admin/settings/translations?q%5B0%5D=invalid')->assertForbidden();

        $this->assertSame($before, $this->counts());
    }

    /** @return array<int, int> */
    private function counts(): array
    {
        return [
            DB::table('translations')->count(),
            DB::table('audit_logs')->count(),
        ];
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }
}
