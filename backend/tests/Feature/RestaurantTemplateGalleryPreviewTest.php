<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RestaurantTemplateGalleryPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'TEMPLATE-GALLERY',
            'name' => 'Template Gallery Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableRestaurantSaasForTestStore($storeId);

        DB::table('smart_ordering_sites')->insert([
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'slug' => 'template-gallery',
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::query()->create([
            'name' => 'Template Gallery Admin',
            'email' => 'template-gallery@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $this->admin->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_gallery_exposes_all_six_production_templates_before_restaurant_content_is_selected(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/smart-ordering/appearance');

        $response->assertOk()
            ->assertSee('Production template gallery')
            ->assertSee('Demo mode:')
            ->assertSee('appearance/demo-preview', false);

        foreach ($this->themes() as $theme) {
            $response->assertSee('data-theme="'.$theme.'"', false);
        }
    }

    public function test_demo_preview_uses_real_renderer_for_every_template_in_arabic_and_english(): void
    {
        $this->actingAs($this->admin);

        foreach ($this->themes() as $theme) {
            foreach (['en', 'ar'] as $locale) {
                $response = $this->get('/admin/smart-ordering/appearance/demo-preview?theme='.$theme.'&lang='.$locale);

                $response->assertOk()
                    ->assertHeader('Cache-Control', 'no-store, private')
                    ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
                    ->assertHeader('X-FOODEX-Preview-State', 'demo')
                    ->assertSee('data-template="'.$theme.'"', false)
                    ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                    ->assertSee($locale === 'ar' ? 'زعفران وستون' : 'Saffron &amp; Stone', false);
            }
        }
    }

    public function test_apply_template_changes_draft_only_and_gallery_reports_current_selection(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/smart-ordering/appearance/apply', [
                'site' => 'template-gallery',
                'theme' => 'premium-arabic-dining',
                'lang' => 'ar',
                'device' => 'tablet',
            ])
            ->assertRedirect();

        $siteId = (int) DB::table('smart_ordering_sites')->where('slug', 'template-gallery')->value('id');
        $draftPayload = json_decode((string) DB::table('smart_ordering_revisions')
            ->where('site_id', $siteId)
            ->where('status', 'draft')
            ->latest('id')
            ->value('payload'), true, 512, JSON_THROW_ON_ERROR);
        $publishedPayload = json_decode((string) DB::table('smart_ordering_revisions')
            ->where('site_id', $siteId)
            ->where('status', 'published')
            ->latest('id')
            ->value('payload'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('premium-arabic-dining', data_get($draftPayload, 'appearance.template'));
        $this->assertSame(1, data_get($draftPayload, 'appearance.template_version'));
        $this->assertNull(data_get($publishedPayload, 'appearance.template'));

        $this->get('/admin/smart-ordering/appearance?site=template-gallery&lang=ar')
            ->assertOk()
            ->assertSee('data-theme="premium-arabic-dining"', false)
            ->assertSee('aria-current="true"', false)
            ->assertSee(__('smart_ordering.appearance.apply_to_draft'));
    }

    public function test_apply_authorizes_explicit_site_before_theme_body_in_arabic_and_english(): void
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $foreignStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'TEMPLATE-GALLERY-FOREIGN',
            'name' => 'Foreign Template Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableRestaurantSaasForTestStore($foreignStore);
        $foreignSiteId = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $foreignStore,
            'slug' => 'template-gallery-foreign',
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ownSiteId = (int) DB::table('smart_ordering_sites')
            ->where('slug', 'template-gallery')->value('id');
        $url = '/admin/smart-ordering/appearance/apply';
        $valid = ['theme' => 'premium-arabic-dining', 'lang' => 'en', 'device' => 'tablet'];
        $before = [
            DB::table('smart_ordering_revisions')->count(),
            DB::table('audit_logs')->count(),
        ];
        $initialLocale = app()->getLocale();

        try {
            foreach (['ar', 'en'] as $locale) {
                $this->admin->forceFill(['locale' => $locale])->saveQuietly();
                app()->setLocale($locale);

                foreach ([
                    ['site' => 'template-gallery-foreign'],
                    ['site' => 'template-gallery-foreign', 'theme' => ['invalid' => 'nested']],
                    ['site' => 'template-gallery-foreign', ...$valid],
                    ['site' => 'unknown-restaurant-template', 'theme' => ['invalid']],
                ] as $denied) {
                    $this->actingAs($this->admin->fresh())->postJson($url, $denied)
                        ->assertNotFound();
                }

                foreach ([
                    [],
                    ['site' => ['invalid'], ...$valid],
                    ['site' => str_repeat('s', 101), ...$valid],
                    ['site' => 'template-gallery'],
                    ['site' => 'template-gallery', 'theme' => ['invalid' => 'nested']],
                ] as $invalid) {
                    $this->actingAs($this->admin->fresh())->postJson($url, $invalid)
                        ->assertUnprocessable();
                }
            }
        } finally {
            app()->setLocale($initialLocale);
        }

        $this->assertSame($before, [
            DB::table('smart_ordering_revisions')->count(),
            DB::table('audit_logs')->count(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            $this->admin->forceFill(['locale' => $locale])->saveQuietly();
            app()->setLocale($locale);
            $this->actingAs($this->admin->fresh())->post($url, [
                'site' => 'template-gallery',
                ...$valid,
            ])->assertRedirect();
        }

        $this->assertDatabaseHas('smart_ordering_revisions', [
            'site_id' => $ownSiteId,
            'status' => 'draft',
        ]);
        $this->assertSame(0, DB::table('smart_ordering_revisions')
            ->where('site_id', $foreignSiteId)->count());
    }

    public function test_demo_preview_rejects_non_production_theme_ids(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/smart-ordering/appearance/demo-preview?theme=quick-bite&lang=en')
            ->assertNotFound();
    }

    /** @return list<string> */
    private function themes(): array
    {
        return [
            'fine-dining-elegant-light',
            'fine-dining-dark-luxury',
            'contemporary-bistro',
            'premium-arabic-dining',
            'modern-minimal',
            'chef-tasting',
        ];
    }
}
