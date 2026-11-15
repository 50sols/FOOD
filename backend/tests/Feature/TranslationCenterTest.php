<?php

namespace Tests\Feature;

use App\Models\AppTranslation;
use App\Models\Role;
use App\Models\User;
use App\Services\TranslationCatalog;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Tests\TestCase;

class TranslationCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_every_registered_translation_has_arabic_and_english_defaults(): void
    {
        $catalog = app(TranslationCatalog::class);

        foreach ($catalog->defaults() as $key => $values) {
            $this->assertNotSame('', trim($values['ar']), "{$key} is missing Arabic.");
            $this->assertNotSame('', trim($values['en']), "{$key} is missing English.");
        }
    }

    public function test_super_admin_can_manage_translation_and_change_is_audited(): void
    {
        $user = $this->userWithRole('SUPER_ADMIN');

        $this->actingAs($user)
            ->get('/admin/settings/translations')
            ->assertOk()
            ->assertSee('مركز إدارة الترجمة');

        $translation = AppTranslation::query()->where('key', 'admin.title')->firstOrFail();

        $this->actingAs($user)
            ->patch("/admin/settings/translations/{$translation->id}", [
                'ar' => 'لوحة فودكس',
                'en' => 'FOODEX Console',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('translations', [
            'id' => $translation->id,
            'ar' => 'لوحة فودكس',
            'en' => 'FOODEX Console',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'translation.updated']);

        $this->getJson('/api/v1/translations/en')
            ->assertOk()
            ->assertJsonPath('direction', 'ltr')
            ->assertJsonFragment(['admin.title' => 'FOODEX Console']);
    }

    public function test_database_translation_loader_isolates_literal_group_names_in_arabic_and_english(): void
    {
        $fixtures = [
            ['qa_literal_rules.title', 'العنوان الحقيقي', 'Real title'],
            ['qa_literal_rules.nested.caption', 'وصف صحيح', 'Correct caption'],
            // Each underscore in the requested group must be literal.
            ['qaXliteral_rules.leaked_first', 'تسريب ١', 'Leaked first'],
            ['qa_literalXrules.leaked_second', 'تسريب ٢', 'Leaked second'],
            ['qa_literal_rules_extra.leaked_suffix', 'تسريب ٣', 'Leaked suffix'],
            // A percent in a literal group is not a request for all groups.
            ['qa%scope_rules.title', 'عنوان النسبة', 'Percent title'],
            ['qaXscope_rules.leaked_percent', 'تسريب ٤', 'Leaked percent'],
            // An exclamation point is the selected LIKE escape character.
            ['qa!scope_rules.title', 'عنوان التعجب', 'Bang title'],
            ['qa!!scope_rules.leaked_bang', 'تسريب ٥', 'Leaked bang'],
        ];

        foreach ($fixtures as [$key, $arabic, $english]) {
            AppTranslation::query()->create([
                'key' => $key,
                'surface' => 'admin',
                'ar' => $arabic,
                'en' => $english,
            ]);
        }

        $translationsBefore = AppTranslation::query()->count();
        $auditBefore = DB::table('audit_logs')->count();
        $loader = app('translation.loader');

        $english = $loader->load('en', 'qa_literal_rules');
        $this->assertSame('Real title', $english['title'] ?? null);
        $this->assertSame('Correct caption', Arr::get($english, 'nested.caption'));
        foreach (['leaked_first', 'leaked_second', 'leaked_suffix'] as $leaked) {
            $this->assertArrayNotHasKey($leaked, $english);
        }
        $arabic = $loader->load('ar', 'qa_literal_rules');
        $this->assertSame('العنوان الحقيقي', $arabic['title'] ?? null);
        $this->assertSame('وصف صحيح', Arr::get($arabic, 'nested.caption'));
        $this->assertArrayNotHasKey('leaked_first', $arabic);

        $this->assertSame(
            ['title' => 'Percent title'],
            $loader->load('en', 'qa%scope_rules'),
        );
        $this->assertSame(
            ['title' => 'عنوان النسبة'],
            $loader->load('ar', 'qa%scope_rules'),
        );
        $this->assertSame(
            ['title' => 'Bang title'],
            $loader->load('en', 'qa!scope_rules'),
        );
        $this->assertSame(
            ['title' => 'عنوان التعجب'],
            $loader->load('ar', 'qa!scope_rules'),
        );

        // Confirm the ordinary translator uses the same loader contract.
        $this->assertSame('Real title', __('qa_literal_rules.title', [], 'en'));
        $this->assertSame('العنوان الحقيقي', __('qa_literal_rules.title', [], 'ar'));
        $this->assertSame($translationsBefore, AppTranslation::query()->count());
        $this->assertSame(
            $auditBefore,
            DB::table('audit_logs')->count(),
        );
    }

    public function test_non_authorized_admin_cannot_manage_translations(): void
    {
        $user = $this->userWithRole('B2B_ADMIN');

        $this->actingAs($user)
            ->get('/admin/settings/translations')
            ->assertForbidden();
    }

    public function test_translation_api_has_safe_code_defaults_before_admin_sync(): void
    {
        $this->getJson('/api/v1/translations/ar')
            ->assertOk()
            ->assertJsonPath('direction', 'rtl')
            ->assertJsonFragment(['customer.splash.title' => 'فودكس']);

        $this->getJson('/api/v1/translations/fr')->assertNotFound();
    }

    public function test_all_admin_language_files_are_available_in_the_catalog_and_public_bundles(): void
    {
        $catalog = app(TranslationCatalog::class);

        foreach (['admin', 'notifications', 'reports', 'mobile_settings'] as $group) {
            foreach (['ar', 'en'] as $locale) {
                $defaults = Arr::dot(require lang_path("{$locale}/{$group}.php"));
                $response = $this->getJson("/api/v1/translations/{$locale}")->assertOk();

                foreach ($defaults as $key => $value) {
                    $fullKey = $group.'.'.$key;
                    $this->assertSame($value, $catalog->defaultsFor($fullKey)[$locale]);
                    $response->assertJsonFragment([$fullKey => $value]);
                }
            }
        }
    }

    public function test_reports_and_mobile_settings_can_be_edited_synced_and_reset(): void
    {
        $this->actingAs($this->userWithRole('SUPER_ADMIN'));
        $catalog = app(TranslationCatalog::class);

        foreach (['reports.title', 'mobile_settings.title'] as $key) {
            $this->get('/admin/settings/translations?surface=admin&q='.urlencode($key))
                ->assertOk()->assertSee($key);

            $translation = AppTranslation::query()->where('key', $key)->firstOrFail();
            $this->patch("/admin/settings/translations/{$translation->id}", [
                'ar' => 'عنوان فودكس مخصص',
                'en' => 'Custom FOODEX title',
            ])->assertRedirect();

            $catalog->syncDefaults();
            $this->assertDatabaseHas('translations', [
                'id' => $translation->id,
                'ar' => 'عنوان فودكس مخصص',
                'en' => 'Custom FOODEX title',
                'surface' => 'admin',
            ]);

            $this->getJson('/api/v1/translations/ar')->assertOk()
                ->assertJsonFragment([$key => 'عنوان فودكس مخصص']);
            $this->getJson('/api/v1/translations/en')->assertOk()
                ->assertJsonFragment([$key => 'Custom FOODEX title']);

            $this->post("/admin/settings/translations/{$translation->id}/reset")->assertRedirect();
            $defaults = $catalog->defaultsFor($key);
            $this->assertDatabaseHas('translations', [
                'id' => $translation->id,
                'ar' => $defaults['ar'],
                'en' => $defaults['en'],
            ]);
            $this->getJson('/api/v1/translations/en')->assertOk()
                ->assertJsonFragment([$key => $defaults['en']]);
        }

        $this->assertDatabaseHas('audit_logs', ['event' => 'translation.updated']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'translation.reset']);
    }

    public function test_unauthorized_admin_cannot_edit_or_reset_new_catalog_groups(): void
    {
        app(TranslationCatalog::class)->syncDefaults();
        $this->actingAs($this->userWithRole('B2B_ADMIN'));

        foreach (['reports.title', 'mobile_settings.title'] as $key) {
            $translation = AppTranslation::query()->where('key', $key)->firstOrFail();
            $this->patch("/admin/settings/translations/{$translation->id}", [
                'ar' => 'تغيير غير مصرح',
                'en' => 'Unauthorized change',
            ])->assertForbidden();
            $this->post("/admin/settings/translations/{$translation->id}/reset")->assertForbidden();
            $this->assertDatabaseHas('translations', [
                'id' => $translation->id,
                'en' => $translation->en,
            ]);
        }
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-translations@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
