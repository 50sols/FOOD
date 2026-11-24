<?php

namespace Tests\Feature;

use App\Enterprise\Health\DatabaseHealthProbe;
use App\Enterprise\Health\ModuleHealthCheck;
use App\Enterprise\Health\ModuleHealthProbe;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleManifestCatalog;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Modules\ModuleWorkflowAttestation;
use App\Models\EnterpriseModule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * P135: operational certification is a reviewed, version-bound workflow
 * proof, never an implication of ENABLED, entitlement or healthy probes.
 */
final class V2ErpOperationalWorkflowEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_registered_erp_module_has_an_explicit_workflow_matrix_without_automatic_approval(): void
    {
        $modules = (array) config('enterprise_modules.modules', []);
        $requirements = (array) config('enterprise_module_readiness.required_workflows', []);

        foreach ($modules as $code => $manifest) {
            if (! in_array($manifest['category'] ?? null, ['finance', 'operations', 'analytics'], true)) {
                continue;
            }

            $this->assertArrayHasKey($code, $requirements);
            $this->assertNotEmpty($requirements[$code]);
            $this->assertContains('audit', $requirements[$code]);
            // Every signed ERP manifest must require at least one actual
            // health check. No empty/misconfigured probe may certify a
            // Finance, WMS, Treasury, Procurement, or BI module.
            $this->assertSame(['database'], $manifest['health_checks'] ?? null, $code);
            $this->assertSame(
                [DatabaseHealthProbe::class],
                config('enterprise_module_health.probes.'.$code),
                'Signed ERP operational readiness requires the matching executed database probe: '.$code,
            );
        }

        $this->assertSame([], config('enterprise_module_readiness.workflow_attestations'));
        $this->assertSame([], config('enterprise_module_readiness.trusted_public_keys'));
        $this->assertSame([], config('enterprise_module_readiness.certified_versions'));
    }

    public function test_new_erp_module_or_missing_workflow_stage_fails_closed_in_arabic_and_english(): void
    {
        $this->enableSample();
        config()->set('enterprise_module_readiness.certified_versions.sample_optional', 1);
        config()->set('enterprise_modules.modules.sample_optional.category', 'operations');
        $readiness = app(ModuleOperationalReadiness::class);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            // A newly registered operational module has no approved matrix.
            $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

            config()->set('enterprise_module_readiness.required_workflows.sample_optional', ['create', 'approve', 'audit']);
            $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

            // A missing workflow stage cannot be silently omitted from proof.
            $evidence = $this->evidence();
            unset($evidence['checks']['audit']);
            config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', $evidence);
            $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
        }
    }

    public function test_missing_or_malformed_manifest_category_cannot_bypass_erp_workflow_gate(): void
    {
        $this->enableSample();
        config()->set('enterprise_module_readiness.certified_versions.sample_optional', 1);
        $readiness = app(ModuleOperationalReadiness::class);

        // A valid non-ERP module retains its independent health/version contract.
        $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        // No workflow matrix exists for this optional module. A missing category
        // must not let a future ERP manifest be mistaken for a certified optional.
        foreach ([null, '', '   ', [], 'unreviewed_domain'] as $invalidCategory) {
            config()->set('enterprise_modules.modules.sample_optional.category', $invalidCategory);

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
            }
        }
    }

    public function test_exact_manifest_and_reviewed_complete_workflow_proof_are_required(): void
    {
        $this->enableSample();
        $this->requireSampleWorkflow();
        $readiness = app(ModuleOperationalReadiness::class);
        $good = $this->evidence();

        config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', $good);
        $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        foreach ([
            ['version' => 2],
            ['head_sha' => 'not-a-git-sha'],
            ['manifest_sha256' => str_repeat('0', 64)],
            ['manifest_sha256' => 'not-a-manifest-hash'],
            ['manifest_sha256' => []],
            ['review_issue' => 0],
            ['ci_run' => 'https://example.invalid/green'],
            ['key_id' => 'untrusted'],
            ['signature' => base64_encode(str_repeat('x', 64))],
            ['checks' => [
                'create' => ['result' => 'passed', 'test' => 'Forged::create'],
                'approve' => ['result' => 'passed', 'test' => 'Synthetic::approve'],
                'audit' => ['result' => 'passed', 'test' => 'Synthetic::audit'],
            ]],
            ['checks' => ['create' => ['result' => 'passed', 'test' => 'only-one-step']]],
        ] as $invalidFields) {
            config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', [
                ...$good,
                ...$invalidFields,
            ]);
            $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
        }

        // Reapproval of the previous module version cannot authorize a new one.
        config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', $good);
        config()->set('enterprise_module_readiness.certified_versions.sample_optional', 2);
        $this->assertSame('uncertified', $readiness->status('sample_optional', 2, ModuleState::ENABLED));
    }

    public function test_signed_manifest_digest_fails_closed_when_dependencies_or_scope_change_without_version_bump(): void
    {
        $this->enableSample();
        $this->requireSampleWorkflow();
        $readiness = app(ModuleOperationalReadiness::class);
        $signed = $this->evidence();
        $manifest = app(ModuleManifestCatalog::class)->require('sample_optional');
        $digest = ModuleWorkflowAttestation::manifestSha256($manifest);

        $this->assertIsString($digest);
        $this->assertSame(64, strlen($digest));
        $this->assertSame($digest, $signed['manifest_sha256']);
        $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        // A manifest version and cryptographically valid original signature
        // cannot approve changed hard dependencies, required core, permissions,
        // capabilities or health checks without a new reviewed attestation.
        foreach ([
            'hard_dependencies' => ['finance'],
            'optional_integrations' => ['finance'],
            'required_core' => ['identity', 'tenant_context', 'audit', 'catalog'],
            'capabilities' => ['unreviewed.sensitive.action'],
            'permissions' => ['finance.post'],
            'health_checks' => ['provider.runtime'],
            'navigation' => ['unreviewed' => ['route' => 'admin.unreviewed']],
        ] as $attribute => $unreviewedDefinition) {
            $original = config('enterprise_modules.modules.sample_optional.'.$attribute);
            config()->set('enterprise_modules.modules.sample_optional.'.$attribute, $unreviewedDefinition);

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $this->assertSame(
                    'uncertified',
                    $readiness->status('sample_optional', 1, ModuleState::ENABLED),
                    $attribute.' cannot reuse a signature for an older manifest.',
                );
            }

            config()->set('enterprise_modules.modules.sample_optional.'.$attribute, $original);
            $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
        }

        // Clearing the trusted key or approval maps must still deny a signed
        // manifest even when the original unmodified manifest is restored.
        config()->set('enterprise_module_readiness.trusted_public_keys', []);
        $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
        $this->assertSame([], config('enterprise_module_readiness.certified_versions.procurement', []));
    }

    public function test_signed_proof_rejects_stale_installed_manifest_version_or_contract_in_arabic_and_english(): void
    {
        $this->enableSample();
        $this->requireSampleWorkflow();
        $this->evidence();
        $readiness = app(ModuleOperationalReadiness::class);
        $registration = EnterpriseModule::query()->where('code', 'sample_optional')->firstOrFail();
        $original = $registration->manifest;

        $this->assertIsArray($original);
        $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        $registration->forceFill(['manifest_version' => 2])->save();
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
        }
        $registration->forceFill(['manifest_version' => 1])->save();

        // The stored manifest itself must not lag a same-version source
        // update or replace a reviewed permission/required-core contract.
        $registration->forceFill([
            'manifest' => [...$original, 'permissions' => ['unreviewed.manage']],
        ])->save();
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
        }

        $registration->forceFill(['manifest' => $original])->save();
        $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        // Neither the installed nor source manifest can authorize an ERP
        // module when true production certification maps are empty.
        config()->set('enterprise_module_readiness.certified_versions', []);
        $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
    }

    public function test_untrusted_or_replayed_signature_never_turns_on_an_erp_module(): void
    {
        $this->enableSample();
        $this->requireSampleWorkflow();
        $readiness = app(ModuleOperationalReadiness::class);
        $signed = $this->evidence();
        $trustedKeys = config('enterprise_module_readiness.trusted_public_keys');

        $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        // A full-looking SHA, trusted URL and named passing tests are not proof.
        config()->set('enterprise_module_readiness.trusted_public_keys', []);
        $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        // Keep the same valid trust root and exact workflow steps to prove
        // that the module identity itself is signed and cannot be replayed.
        config()->set('enterprise_module_readiness.trusted_public_keys', $trustedKeys);
        config()->set('enterprise_module_readiness.required_workflows.finance', ['create', 'approve', 'audit']);
        config()->set('enterprise_module_readiness.certified_versions.finance', 1);
        config()->set('enterprise_module_readiness.workflow_attestations.finance', $signed);
        $this->assertSame('uncertified', $readiness->status('finance', 1, ModuleState::ENABLED));

        // Even an allowed key cannot approve new checks by changing the payload.
        config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', [
            ...$signed,
            'head_sha' => str_repeat('b', 40),
        ]);
        $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
    }

    public function test_signed_workflow_evidence_cannot_relabel_a_trusted_key_or_tamper_with_valid_looking_review_metadata(): void
    {
        $this->enableSample();
        $this->requireSampleWorkflow();
        $signed = $this->evidence();
        $readiness = app(ModuleOperationalReadiness::class);

        // Even correctly structured metadata cannot certify an attestation
        // belonging to the pre-transfer owner: only current canonical Actions
        // links can enter a signed workflow payload.
        $steps = (array) config('enterprise_module_readiness.required_workflows.sample_optional');
        $this->assertNotNull(ModuleWorkflowAttestation::payload('sample_optional', 1, $steps, $signed));
        $this->assertNull(ModuleWorkflowAttestation::payload('sample_optional', 1, $steps, [
            ...$signed,
            'ci_run' => 'https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/1',
        ]));

        // A second trusted ID with the SAME public key must not be able to
        // relabel an attestation signed for the original signer identity.
        $publicKey = config('enterprise_module_readiness.trusted_public_keys.synthetic-test');
        config()->set('enterprise_module_readiness.trusted_public_keys.synthetic-copy', $publicKey);

        $tamperedChecks = $signed['checks'];
        $tamperedChecks['create']['test'] = 'Forged::passing_test';

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', $signed);
            $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

            foreach ([
                ['key_id' => 'synthetic-copy'],
                ['review_issue' => 2859],
                ['ci_run' => 'https://github.com/50sols/FOOD/actions/runs/2'],
                ['ci_run' => 'https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/1'],
                ['checks' => $tamperedChecks],
            ] as $mutation) {
                config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', [
                    ...$signed,
                    ...$mutation,
                ]);
                $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
            }
        }
    }

    public function test_signed_workflow_proof_rechecks_persisted_lifecycle_instead_of_trusting_stale_enabled_argument(): void
    {
        $this->enableSample();
        $this->requireSampleWorkflow();
        $this->evidence();

        $readiness = app(ModuleOperationalReadiness::class);
        $lifecycle = app(ModuleLifecycleManager::class);

        $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        // A caller may have read ENABLED immediately before another request
        // suspended/disabled the persisted module. The signed manifest and
        // trusted workflow proof alone must not let the stale caller say ready.
        foreach ([ModuleState::SUSPENDED, ModuleState::MAINTENANCE, ModuleState::DISABLED] as $state) {
            $lifecycle->transition('sample_optional', $state);

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $this->assertSame(
                    'uncertified',
                    $readiness->status('sample_optional', 1, ModuleState::ENABLED),
                    $state->value.' persisted lifecycle must deny stale caller state.',
                );
                $this->assertSame('inactive', $readiness->status('sample_optional', 1, $state));
            }

            if ($state === ModuleState::DISABLED) {
                $lifecycle->transition('sample_optional', ModuleState::ENABLING);
            }

            $lifecycle->transition('sample_optional', ModuleState::ENABLED);
            $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
        }

        // This test changes only disposable Laravel fixture module state.
        // No production certification, grants or trust keys are provisioned.
    }

    public function test_signed_erp_module_with_no_declared_health_checks_cannot_be_operationally_ready(): void
    {
        $this->enableSample();
        $this->requireSampleWorkflow();
        $readiness = app(ModuleOperationalReadiness::class);

        // An optional platform module is allowed to use its existing empty
        // health-check contract; requiring an ERP check must not regress it.
        $this->evidence();
        $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        // Change the same isolated module into an ERP operations module and
        // obtain a fresh correctly signed synthetic proof for that new
        // manifest. The missing health checks, not an invalid signature,
        // must now prevent a false ready verdict.
        config()->set('enterprise_modules.modules.sample_optional.category', 'operations');
        app(ModuleRegistry::class)->syncManifest('sample_optional');
        $this->evidence();
        $this->assertSame([], app(ModuleManifestCatalog::class)->require('sample_optional')->healthChecks);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame(
                'unhealthy',
                $readiness->status('sample_optional', 1, ModuleState::ENABLED),
                'A signed ERP workflow is not a substitute for an executed health probe.',
            );
        }
    }

    public function test_cached_healthy_report_becomes_unhealthy_when_an_additional_probe_expires(): void
    {
        $this->enableSample();
        config()->set('enterprise_modules.modules.sample_optional.category', 'operations');
        config()->set('enterprise_modules.modules.sample_optional.health_checks', ['database']);
        config()->set('enterprise_module_health.probes.sample_optional', [
            DatabaseHealthProbe::class,
            V2ExpiringSupplementaryProbe::class,
        ]);
        app(ModuleRegistry::class)->syncManifest('sample_optional');
        $this->requireSampleWorkflow();
        $this->evidence();

        $readiness = app(ModuleOperationalReadiness::class);
        $previousClock = CarbonImmutable::getTestNow();
        $start = CarbonImmutable::parse('2026-01-01T12:00:00Z');
        try {
            CarbonImmutable::setTestNow($start);
            $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
            CarbonImmutable::setTestNow($start->addSeconds(59));
            $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
            CarbonImmutable::setTestNow($start->addMinute());
            $this->assertSame('unhealthy', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
        } finally {
            CarbonImmutable::setTestNow($previousClock);
        }
    }

    public function test_health_probe_cannot_return_ready_after_module_is_suspended_during_evaluation(): void
    {
        $this->enableSample();
        config()->set('enterprise_modules.modules.sample_optional.category', 'operations');
        config()->set('enterprise_modules.modules.sample_optional.health_checks', ['database']);
        config()->set('enterprise_module_health.probes.sample_optional', [V2SuspendDuringHealthProbe::class]);
        app(ModuleRegistry::class)->syncManifest('sample_optional');
        $this->requireSampleWorkflow();
        $this->evidence();

        // The signed manifest and check are valid. The probe returns healthy
        // but suspends the persisted module in the middle of the readiness
        // decision, so the earlier ENABLED snapshot cannot authorize access.
        $this->assertSame(
            'uncertified',
            app(ModuleOperationalReadiness::class)->status('sample_optional', 1, ModuleState::ENABLED),
        );
        $this->assertSame(
            ModuleState::SUSPENDED,
            app(ModuleRegistry::class)->state('sample_optional'),
        );
    }

    public function test_health_probe_cannot_return_ready_after_installed_manifest_changes(): void
    {
        $this->enableSample();
        config()->set('enterprise_modules.modules.sample_optional.category', 'operations');
        config()->set('enterprise_modules.modules.sample_optional.health_checks', ['database']);
        config()->set('enterprise_module_health.probes.sample_optional', [V2ChangeManifestDuringHealthProbe::class]);
        app(ModuleRegistry::class)->syncManifest('sample_optional');
        $this->requireSampleWorkflow();
        $this->evidence();

        // Although the probe reports a healthy database, it changes the
        // installed permissions after the original signed-manifest check.
        // The readiness result must not authorize this altered manifest.
        $this->assertSame(
            'uncertified',
            app(ModuleOperationalReadiness::class)->status('sample_optional', 1, ModuleState::ENABLED),
        );
        $this->assertSame(
            ['unreviewed.manage'],
            EnterpriseModule::query()->where('code', 'sample_optional')->firstOrFail()->manifest['permissions'],
        );
    }

    public function test_health_probe_cannot_return_ready_after_signed_workflow_certification_is_revoked(): void
    {
        $this->enableSample();
        config()->set('enterprise_modules.modules.sample_optional.category', 'operations');
        config()->set('enterprise_modules.modules.sample_optional.health_checks', ['database']);
        config()->set('enterprise_module_health.probes.sample_optional', [V2RevokeAttestationDuringHealthProbe::class]);
        app(ModuleRegistry::class)->syncManifest('sample_optional');
        $this->requireSampleWorkflow();
        $this->evidence();

        // A real probe may run after the signature has been checked. If the
        // trusted attestation is revoked during that probe, readiness must
        // not reuse the earlier signed evidence as an active authorization.
        $this->assertSame(
            'uncertified',
            app(ModuleOperationalReadiness::class)->status('sample_optional', 1, ModuleState::ENABLED),
        );
        $this->assertSame([], config('enterprise_module_readiness.workflow_attestations.sample_optional'));
        $this->assertSame(
            ModuleState::ENABLED,
            app(ModuleRegistry::class)->state('sample_optional'),
        );
    }

    public function test_health_probe_cannot_return_ready_after_certified_version_is_revoked(): void
    {
        $this->enableSample();
        config()->set('enterprise_modules.modules.sample_optional.category', 'operations');
        config()->set('enterprise_modules.modules.sample_optional.health_checks', ['database']);
        config()->set('enterprise_module_health.probes.sample_optional', [V2RevokeCertifiedVersionDuringHealthProbe::class]);
        app(ModuleRegistry::class)->syncManifest('sample_optional');
        $this->requireSampleWorkflow();
        $signedAttestation = $this->evidence();

        // Certification can be withdrawn independently of the signature.
        // Health probes cannot reuse the pre-probe version approval.
        $this->assertSame(
            'uncertified',
            app(ModuleOperationalReadiness::class)->status('sample_optional', 1, ModuleState::ENABLED),
        );
        $this->assertNull(config('enterprise_module_readiness.certified_versions.sample_optional'));
        $this->assertSame(
            $signedAttestation,
            config('enterprise_module_readiness.workflow_attestations.sample_optional'),
        );
        $this->assertSame(ModuleState::ENABLED, app(ModuleRegistry::class)->state('sample_optional'));
    }

    public function test_operational_proof_never_bypasses_disabled_lifecycle_or_failing_health(): void
    {
        config()->set('enterprise_module_readiness.certified_versions.sample_optional', 1);
        config()->set('enterprise_module_readiness.required_workflows.sample_optional', ['create', 'approve', 'audit']);
        config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', $this->evidence());

        $readiness = app(ModuleOperationalReadiness::class);
        $this->assertSame('inactive', $readiness->status('sample_optional', 1, ModuleState::DISABLED));

        $this->enableSample();
        config()->set('enterprise_module_health.probes.sample_optional', [V2FailedWorkflowProbe::class]);

        $this->assertSame('unhealthy', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
    }

    private function requireSampleWorkflow(): void
    {
        config()->set('enterprise_module_readiness.certified_versions.sample_optional', 1);
        config()->set('enterprise_module_readiness.required_workflows.sample_optional', ['create', 'approve', 'audit']);
    }

    private function enableSample(): void
    {
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('sample_optional', ModuleState::ENABLING);
        $lifecycle->transition('sample_optional', ModuleState::ENABLED);
    }

    /** @return array<string, mixed> */
    private function evidence(): array
    {
        SyntheticErpWorkflowEvidence::install();

        return (array) config('enterprise_module_readiness.workflow_attestations.sample_optional', []);
    }
}

final class V2ExpiringSupplementaryProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        return ModuleHealthCheck::healthy('supplementary', staleAt: CarbonImmutable::now('UTC')->addMinute());
    }
}

final class V2SuspendDuringHealthProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        app(ModuleLifecycleManager::class)->transition('sample_optional', ModuleState::SUSPENDED);

        return ModuleHealthCheck::healthy('database');
    }
}

final class V2ChangeManifestDuringHealthProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        $module = EnterpriseModule::query()->where('code', 'sample_optional')->firstOrFail();
        $module->forceFill([
            'manifest' => [...$module->manifest, 'permissions' => ['unreviewed.manage']],
        ])->save();

        return ModuleHealthCheck::healthy('database');
    }
}

final class V2RevokeAttestationDuringHealthProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', []);

        return ModuleHealthCheck::healthy('database');
    }
}

final class V2RevokeCertifiedVersionDuringHealthProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        config()->set('enterprise_module_readiness.certified_versions.sample_optional', null);

        return ModuleHealthCheck::healthy('database');
    }
}

final class V2FailedWorkflowProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        return ModuleHealthCheck::error('runtime');
    }
}
