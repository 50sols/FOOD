<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Search\GlobalSearchProvider;
use App\Enterprise\Search\GlobalSearchRegistry;
use App\Models\User;
use Tests\TestCase;

final class V2RevokedGlobalSearchRegistryTest extends TestCase
{
    public function test_disabled_identity_never_reaches_core_provider_or_optional_module_scope(): void
    {
        $factoryCalls = 0;
        $scopeCalls = 0;
        $registry = new GlobalSearchRegistry(
            app(ModuleRegistry::class),
            app(ModuleEntitlementResolver::class),
        );

        $coreFactory = function () use (&$factoryCalls): GlobalSearchProvider {
            $factoryCalls++;

            return new class implements GlobalSearchProvider
            {
                public function search(User $actor, string $query, int $limit): array
                {
                    return [[
                        'type' => 'probe',
                        'type_label' => 'Probe',
                        'title' => $query,
                        'subtitle' => '',
                        'url' => '/admin/probe',
                    ]];
                }
            };
        };
        $registry->registerCore('probe', $coreFactory);
        $registry->registerModule(
            'optional_probe',
            'sample_optional',
            function (User $actor) use (&$scopeCalls): EntitlementScope {
                $scopeCalls++;

                return EntitlementScope::platform();
            },
            function () use (&$factoryCalls): GlobalSearchProvider {
                $factoryCalls++;

                return new class implements GlobalSearchProvider
                {
                    public function search(User $actor, string $query, int $limit): array
                    {
                        return [];
                    }
                };
            },
        );

        $revoked = (new User())->forceFill(['is_active' => false]);
        $this->assertSame([], $registry->search($revoked, 'sensitive', 8));
        $this->assertSame(0, $factoryCalls, 'Revoked identity must not resolve provider factories');
        $this->assertSame(0, $scopeCalls, 'Revoked identity must not resolve optional scope');

        // The active regression checks a core-only registry; optional
        // module discovery depends on migrated entitlement tables and is not
        // part of this revocation boundary.
        $activeRegistry = new GlobalSearchRegistry(
            app(ModuleRegistry::class),
            app(ModuleEntitlementResolver::class),
        );
        $activeRegistry->registerCore('probe', $coreFactory);

        $active = (new User())->forceFill(['is_active' => true]);
        $this->assertSame([[
            'type' => 'probe',
            'type_label' => 'Probe',
            'title' => 'safe',
            'subtitle' => '',
            'url' => '/admin/probe',
        ]], $activeRegistry->search($active, 'safe', 2));
        $this->assertSame(1, $factoryCalls, 'Active core provider must remain callable');
    }
}
