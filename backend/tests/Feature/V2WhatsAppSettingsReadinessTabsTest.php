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
                ->assertDontSee('data-whatsapp-panel="create"', false)
                ->assertDontSee('data-whatsapp-panel="health"', false)
                ->assertDontSee('name="credential_secret"', false)
                ->assertDontSee('name="provider_metadata"', false)
                ->assertDontSee('name="webhook_metadata"', false);

            // Inactive tabs are not merely CSS-hidden: the server must not
            // include their forms or sensitive state in the returned HTML.
            $newProfile = $this->actingAs($actor)->get(route('admin.whatsapp.connections.index', ['tab' => 'create']));
            $newProfile->assertOk()->assertSee('name="name"', false)
                ->assertSee('data-whatsapp-panel="create"', false)
                ->assertDontSee('data-whatsapp-panel="profiles"', false)
                ->assertDontSee('data-whatsapp-panel="health"', false);

            $health = $this->actingAs($actor)->get(route('admin.whatsapp.connections.index', ['tab' => 'health']));
            $health->assertOk()->assertSee('data-whatsapp-panel="health"', false)
                ->assertDontSee('data-whatsapp-panel="profiles"', false)
                ->assertDontSee('data-whatsapp-panel="create"', false)
                ->assertDontSee('name="credential_secret"', false);

            foreach ([['tab' => 'not-a-section'], ['tab' => ['create']]] as $invalid) {
                $safe = $this->actingAs($actor)->get(route('admin.whatsapp.connections.index', $invalid));
                $safe->assertOk()->assertSee('data-whatsapp-panel="profiles"', false)
                    ->assertDontSee('data-whatsapp-panel="create"', false)
                    ->assertDontSee('data-whatsapp-panel="health"', false);
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
            $readiness->assertDontSee('data-whatsapp-panel="controls"', false)
                ->assertDontSee('name="action"', false);

            $bad = $this->actingAs($actor)->get(route('admin.whatsapp.index', ['tab' => ['controls']]));
            $bad->assertOk()->assertSee('data-whatsapp-panel="readiness"', false)
                ->assertDontSee('data-whatsapp-panel="controls"', false)
                ->assertDontSee('name="action"', false);

            $controls = $this->actingAs($actor)->get(route('admin.whatsapp.index', ['tab' => 'controls']));
            $controls->assertOk();
            if (str_contains($controls->getContent(), 'data-whatsapp-panel="controls"')) {
                $controls->assertDontSee('data-whatsapp-panel="readiness"', false);
            }
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

    public function test_invalid_create_preserves_only_nonsecret_fields_and_returns_to_create_tab_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $actor = $this->superAdmin($locale);
            $privateToken = 'confidential-'.strtoupper($locale).'-create-token';

            $response = $this->actingAs($actor)
                ->from(route('admin.administration.index'))
                ->post(route('admin.whatsapp.connections.store'), [
                    'name' => 'Test business connection',
                    'display_phone_number' => '+96550000088',
                    'provider_type' => 'invalid provider type',
                    'credential_secret' => $privateToken,
                    'webhook_verify_token' => 'private-verify-value',
                    'webhook_signing_secret' => 'private-signing-value',
                    'provider_metadata' => '{"access_token":"unsafe-legacy-raw-json"}',
                ]);

            $response->assertRedirect(route('admin.whatsapp.connections.index', ['tab' => 'create']))
                ->assertSessionHasErrors('provider_type')
                ->assertSessionHas('_old_input.name', 'Test business connection');

            $old = session('_old_input', []);
            $this->assertArrayNotHasKey('credential_secret', $old);
            $this->assertArrayNotHasKey('webhook_verify_token', $old);
            $this->assertArrayNotHasKey('webhook_signing_secret', $old);
            $this->assertArrayNotHasKey('provider_metadata', $old);
            $this->assertArrayNotHasKey('webhook_metadata', $old);
            $this->assertStringNotContainsString($privateToken, json_encode($old, JSON_THROW_ON_ERROR));
            $this->assertSame(0, WhatsAppConnectionProfile::query()->count());

            $page = $this->actingAs($actor)
                ->get(route('admin.whatsapp.connections.index', ['tab' => 'create']));
            $page->assertOk()->assertSee('value="Test business connection"', false);
            $this->assertDoesNotMatchRegularExpression(
                '/data-whatsapp-panel="create"[^>]*hidden/',
                $page->getContent(),
            );
        }
    }

    public function test_invalid_credential_rotation_and_legacy_metadata_edit_do_not_flash_sensitive_values(): void
    {
        $actor = $this->superAdmin();
        $this->actingAs($actor)->post(route('admin.whatsapp.connections.store'), [
            'name' => 'Protected profile',
            'display_phone_number' => '+96550000089',
            'provider_type' => 'meta_cloud',
        ])->assertRedirect(route('admin.whatsapp.connections.index'));

        $profile = WhatsAppConnectionProfile::query()->firstOrFail();
        $before = $profile->configuration_revision;
        $secret = str_repeat('S', 10001);
        $this->actingAs($actor)
            ->from(route('admin.administration.index'))
            ->put(route('admin.whatsapp.connections.credential', $profile), [
                'credential_secret' => $secret,
                'webhook_verify_token' => 'not-for-session',
            ])->assertRedirect(route('admin.whatsapp.connections.index', ['tab' => 'profiles']))
            ->assertSessionHasErrors('credential_secret');

        $this->assertSame([], session('_old_input', []));
        $this->assertSame($before, $profile->fresh()->configuration_revision);

        $this->actingAs($actor)
            ->from(route('admin.administration.index'))
            ->patch(route('admin.whatsapp.connections.update', $profile), [
                'name' => 'Bad metadata',
                'display_phone_number' => '+96550000089',
                'provider_type' => 'meta_cloud',
                'provider_metadata' => '{"access_token":"private-do-not-flash"}',
            ])->assertRedirect(route('admin.whatsapp.connections.index', ['tab' => 'profiles']))
            ->assertSessionHasErrors('provider_metadata');

        $this->assertSame([], session('_old_input', []));
        $this->assertSame('Protected profile', $profile->fresh()->name);
        $this->assertSame($before, $profile->fresh()->configuration_revision);
    }

    public function test_provider_test_errors_are_generic_and_localized_with_no_raw_diagnostic_flash(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $actor = $this->superAdmin($locale);
            $this->actingAs($actor)->post(route('admin.whatsapp.connections.store'), [
                'name' => 'Unconfigured provider '.$locale,
                'display_phone_number' => $locale === 'ar' ? '+96550000092' : '+96550000090',
                'provider_type' => 'meta_cloud',
                'provider_phone_number_id' => 'not-a-real-provider-id',
            ])->assertRedirect(route('admin.whatsapp.connections.index'));

            $profile = WhatsAppConnectionProfile::query()
                ->where('name', 'Unconfigured provider '.$locale)->firstOrFail();

            $this->actingAs($actor)
                ->from(route('admin.administration.index'))
                ->post(route('admin.whatsapp.connections.test-send', $profile), [
                    'recipient' => '+96550000091',
                    'message' => 'Do not send this test message',
                ])
                ->assertRedirect(route('admin.whatsapp.connections.index', ['tab' => 'profiles']))
                ->assertSessionHasErrors('recipient');

            $errors = session('errors')->get('recipient');
            $this->assertSame([__('whatsapp.connections.test_send_failed')], $errors);
            $this->assertStringNotContainsString('Meta connection is not configured.', implode(' ', $errors));
            $this->assertSame([], session('_old_input', []));
            $this->assertNull($profile->fresh()->last_outbound_at);
        }
    }
}
