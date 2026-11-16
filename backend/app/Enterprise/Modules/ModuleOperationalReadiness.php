<?php

namespace App\Enterprise\Modules;

use App\Enterprise\Health\ModuleHealthService;
use App\Enterprise\Health\ModuleHealthStatus;

/**
 * Version-scoped operational attestation is separate from lifecycle ENABLED.
 * A healthy probe or a broad entitlement cannot certify an unreviewed module.
 */
final class ModuleOperationalReadiness
{
    public function __construct(private readonly ModuleHealthService $health) {}

    public function status(string $code, int $manifestVersion, ModuleState $state): string
    {
        if ($state !== ModuleState::ENABLED) {
            return 'inactive';
        }

        $certifiedVersions = config('enterprise_module_readiness.certified_versions', []);
        if (! is_array($certifiedVersions)
            || ($certifiedVersions[$code] ?? null) !== $manifestVersion) {
            return 'uncertified';
        }

        // Gated evidence is separate from version certification and health.
        // Every ERP workflow must have a reviewed, version-scoped CI proof;
        // never infer operational readiness from ENABLED or healthy probes.
        if (! $this->hasWorkflowEvidence($code, $manifestVersion)) {
            return 'uncertified';
        }

        return $this->health->report($code)->status === ModuleHealthStatus::HEALTHY
            ? 'ready'
            : 'unhealthy';
    }

    private function hasWorkflowEvidence(string $code, int $manifestVersion): bool
    {
        $category = config('enterprise_modules.modules.'.$code.'.category');
        $required = config('enterprise_module_readiness.required_workflows', []);

        // A missing or malformed manifest category must not make an unknown
        // ERP module appear optional and evade its workflow evidence gate.
        if (! is_string($category) || trim($category) === '') {
            return false;
        }

        // Newly registered ERP modules must fail closed until a complete
        // workflow matrix is explicitly declared. Non-ERP modules retain
        // their independent version/health readiness contract.
        $erpCategory = in_array($category, ['operations', 'finance', 'analytics'], true);
        if (! is_array($required)) {
            return false;
        }

        if (! $erpCategory && ! array_key_exists($code, $required)) {
            return true;
        }

        $steps = $required[$code] ?? null;
        if (! is_array($steps) || $steps === []) {
            return false;
        }

        $attestations = config('enterprise_module_readiness.workflow_attestations', []);
        $attestation = is_array($attestations) ? ($attestations[$code] ?? null) : null;
        if (! is_array($attestation) || ($attestation['version'] ?? null) !== $manifestVersion) {
            return false;
        }

        $payload = ModuleWorkflowAttestation::payload($code, $manifestVersion, $steps, $attestation);

        return $payload !== null && ModuleWorkflowAttestation::hasTrustedSignature($attestation, $payload);
    }
}
