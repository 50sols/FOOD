<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\ConnectionProfileStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppConnectionProfile;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2WhatsAppSettingsReadinessTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    private function superAdmin(string $locale = 'en'): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }

    public function test_connections_tabs_are_strict_and_localized_with_no_raw_metadata_editor(): void
    {
        foreach (['en' => ['ltr', 'WhatsApp connections'], 'ar' => ['rtl', 'اتصالات واتساب']] as $locale => [$direction, $heading]) {
            $actor = $this->superAdmin($locale);
            $base = route('admin.whatsapp.connections.index');

            $response = $this->actingAs($actor)->get($base);
            $response->assertOk()->assertSee($heading)->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-whatsapp-panel="profiles"', false)
                ->assertSee('data-whatsapp-panel="create"', false)
                ->assertSee('data-whatsapp-panel="health"', false)
                ->assertDontSee('name="provider_metadata"', false)
                ->assertDontSee('name="webhook_metadata"', false);

            $this->assertMatchesRegularExpression('/data-whatsapp-panel="health"[^>]*hidden/', $response->getContent());
            $this->assertMatchesRegularExpression('/data-whatsapp-panel="create"[^>]*hidden/', $response->getContent());

            $newProfile = $this->actingAs($actor)->get(route('admin.whatsapp.connections.index', ['tab' => 'create']));
            $newProfile->assertOk()->assertSee('name="name"', false);
            $this->assertDoesNotMatchRegularExpression('/data-whatsapp-panel="create"[^>]*hidden/', $newProfile->getContent());

            foreach ([['tab' => 'not-a-section'], ['tab' => ['create']]] as $invalid) {
                $safe = $this->actingAs($actor)->get(route('admin.whatsapp.connections.index', $invalid));
                $safe->assertOk();
                $this->assertMatchesRegularExpression('/data-whatsapp-panel="create"[^>]*hidden/', $safe->getContent());
            }
        }
    }

    public function test_edit_without_raw_json_preserves_existing_metadata_and_requires_real_provider_validation(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor)->post(route('admin.whatsapp.connections.store'), [
            'name' => 'Meta test',
            'display_phone_number' => '+96550000045',
            'normalized_phone_number' => '+96550000045',
            'provider_type' => 'meta_cloud',
            'provider_phone_number_id' => 'test-phone-external',
            'provider_metadata' => '{"region":"test-only"}',
            'webhook_metadata' => '{"routing":"staging"}',
        ])->assertRedirect(route('admin.whatsapp.connections.index'));

        $profile = WhatsAppConnectionProfile::query()->firstOrFail();
        $this->assertSame(ConnectionProfileStatus::DRAFT, $profile->status);
        $this->assertSame(['region' => 'test-only'], $profile->provider_metadata);
        $this->assertSame(['routing' => 'staging'], $profile->webhook_metadata);

        $this->actingAs($actor)->patch(route('admin.whatsapp.connections.update', $profile), [
            'name' => 'Meta test revised',
            'display_phone_number' => '+96550000045',
            'normalized_phone_number' => '+96550000045',
            'provider_type' => 'meta_cloud',
            'provider_phone_number_id' => 'test-phone-external',
        ])->assertRedirect(route('admin.whatsapp.connections.index'));

        $profile->refresh();
        $this->assertSame('Meta test revised', $profile->name);
        $this->assertSame(['region' => 'test-only'], $profile->provider_metadata);
        $this->assertSame(['routing' => 'staging'], $profile->webhook_metadata);
        $this->assertSame(ConnectionProfileStatus::DRAFT, $profile->status);
        $this->assertNull($profile->last_validated_at);

        $this->actingAs($actor)->get(route('admin.whatsapp.connections.index', ['tab' => 'health']))
            ->assertOk()->assertSee('Global WhatsApp module state')
            ->assertDontSee('name="provider_metadata"', false);
    }

    public function test_module_readiness_and_controls_tabs_are_read_only_navigation_not_grants(): void
    {
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor = $this->superAdmin($locale);
            $readiness = $this->actingAs($actor)->get(route('admin.whatsapp.index'));
            $readiness->assertOk()->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-whatsapp-panel="readiness"', false);
            $this->assertMatchesRegularExpression('/data-whatsapp-panel="readiness"[^>]*>/', $readiness->getContent());

            $bad = $this->actingAs($actor)->get(route('admin.whatsapp.index', ['tab' => ['controls']]));
            $bad->assertOk();
            $this->assertDoesNotMatchRegularExpression('/data-whatsapp-panel="readiness"[^>]*hidden/', $bad->getContent());
        }

        $ordinary = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->actingAs($ordinary)->get(route('admin.whatsapp.index'))->assertForbidden();
        $this->actingAs($ordinary)->get(route('admin.whatsapp.connections.index', ['tab' => 'create']))->assertForbidden();
        $this->actingAs($ordinary)->post(route('admin.whatsapp.connections.store'), [
            'name' => 'unauthorized',
            'display_phone_number' => '+96550000077',
            'provider_type' => 'meta_cloud',
        ])->assertForbidden();
        $this->assertSame(0, WhatsAppConnectionProfile::query()->where('name', 'unauthorized')->count());

        $inactive = $this->superAdmin();
        $inactive->forceFill(['is_active' => false])->save();
        $this->actingAs($inactive)->get(route('admin.whatsapp.connections.index'))->assertForbidden();
    }
}
