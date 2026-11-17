<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SmartOrderingSettingsKeyboardTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_existing_settings_tabs_have_one_tabstop_associated_panels_and_accessible_44px_rail(): void
    {
        $source = file_get_contents(resource_path('views/admin/smart-ordering.blade.php'));
        $this->assertIsString($source);

        $this->assertStringContainsString('role="tablist" aria-orientation="horizontal"', $source);
        $this->assertStringContainsString('flex-wrap:nowrap', $source);
        $this->assertStringContainsString('overflow-x:auto', $source);
        $this->assertStringContainsString('max-width:100%', $source);
        $this->assertStringContainsString('min-width:0', $source);
        $this->assertStringContainsString('flex:0 0 auto;min-height:44px', $source);
        $this->assertStringContainsString('so-settings-tab:focus-visible', $source);
        $this->assertStringNotContainsString('min-height:35px;padding-inline:11px', $source);

        foreach (['overview', 'launch', 'site', 'content', 'modules'] as $index => $name) {
            $this->assertStringContainsString(
                'id="so-settings-tab-'.$name.'" aria-controls="so-settings-panel-'.$name.'" aria-selected="'.($index === 0 ? 'true' : 'false').'" tabindex="'.($index === 0 ? '0' : '-1').'"',
                $source,
            );
            $this->assertStringContainsString(
                'id="so-settings-panel-'.$name.'" role="tabpanel" aria-labelledby="so-settings-tab-'.$name.'" tabindex="0"',
                $source,
            );
        }
        $this->assertSame(5, substr_count($source, 'role="tabpanel"'));
        $this->assertSame(5, substr_count($source, 'role="tab"'));
        $this->assertSame(1, substr_count($source, 'aria-selected="true" tabindex="0"'));
        $this->assertSame(1, substr_count($source, 'tabindex="0" data-so-settings-tab'));
        $this->assertSame(4, substr_count($source, 'tabindex="-1" data-so-settings-tab'));
    }

    public function test_arrow_home_end_and_native_activation_preserve_store_scoped_wizard(): void
    {
        $source = file_get_contents(resource_path('views/admin/smart-ordering.blade.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString("tabs.addEventListener('keydown', (event) => {", $source);
        $this->assertStringContainsString("if (!buttons.includes(event.target)) return;", $source);
        foreach (['ArrowRight', 'ArrowLeft', 'Home', 'End'] as $key) {
            $this->assertStringContainsString("event.key === '".$key."'", $source);
        }
        $this->assertStringContainsString("window.getComputedStyle(tabs).direction === 'rtl'", $source);
        $this->assertStringContainsString("current + (rtl ? -1 : 1)", $source);
        $this->assertStringContainsString("current + (rtl ? 1 : -1)", $source);
        $this->assertStringContainsString('event.preventDefault();', $source);
        $this->assertStringContainsString('activate(destination.dataset.soSettingsTab);', $source);
        $this->assertStringContainsString('destination.focus();', $source);
        $this->assertStringContainsString("else return; // Native Enter/Space click", $source);
        $this->assertStringContainsString('sessionStorage.setItem(storageKey, name)', $source);
        $this->assertStringContainsString("history.replaceState(null, '', '#settings-' + name)", $source);
        $this->assertStringContainsString("foodex:restaurant-settings-tab:", $source);
        $this->assertStringContainsString("route('admin.smart-ordering.revisions.publish'", $source);
    }

    public function test_authorized_restaurant_settings_renders_bilingual_tabs_but_denies_other_store_and_disabled_module(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $store = $this->store('B2C', 'SO-TAB-'.strtoupper($locale));
            $otherStore = $this->store('B2C', 'SO-OTHER-'.strtoupper($locale));
            $manager = $this->storeAdmin($store->id, $locale);
            app()->setLocale($locale);
            // An authenticated store manager must not bypass the server's
            // real entitlement check even though the tab markup is client-side.
            $this->actingAs($manager)
                ->get(route('admin.smart-ordering.index', ['store_id' => $store->id]))
                ->assertForbidden();

            $this->enableRestaurantSaasForTestStore($store->id);
            app(SmartOrderingSiteService::class)->ensureForStore($store->id, $manager);

            $this->actingAs($manager)
                ->get(route('admin.smart-ordering.index', ['store_id' => $store->id]))
                ->assertOk()
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee('role="tablist"', false)
                ->assertSee('aria-controls="so-settings-panel-overview"', false)
                ->assertSee('role="tabpanel"', false)
                ->assertSee($locale === 'ar' ? 'أقسام الإعدادات' : 'Settings sections');

            $this->actingAs($manager)
                ->get(route('admin.smart-ordering.index', ['store_id' => $otherStore->id]))
                ->assertNotFound();

        }
    }

    private function store(string $type, string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', $type)->value('id');

        return Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }

    private function storeAdmin(int $storeId, string $locale): User
    {
        $user = User::query()->create([
            'name' => 'Smart Ordering tab audit',
            'email' => 'so-tabs-'.$locale.'@example.test',
            'password' => 'disposable-ci-only',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
