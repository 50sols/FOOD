<?php

namespace App\Enterprise\Modules;

use App\Enterprise\Health\ModuleHealthService;
use App\Enterprise\Health\ModuleHealthStatus;
use Throwable;

/**
 * Version-scoped operational attestation is separate from lifecycle ENABLED.
 * A healthy probe or a broad entitlement cannot certify an unreviewed module.
 */
final class ModuleOperationalReadiness
{
    public function __construct(
        private readonly ModuleHealthService $health,
        private readonly ModuleManifestCatalog $manifests,
        private readonly ModuleRegistry $registry,
    ) {}

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

        $health = $this->health->report($code);
        if ($health->status !== ModuleHealthStatus::HEALTHY) {
            return 'unhealthy';
        }

        try {
            $requiredChecks = $this->manifests->require($code)->healthChecks;
        } catch (Throwable) {
            return 'unhealthy';
        }

        // A signed ERP workflow is not operational health evidence if its
        // manifest declares no checks at all. Optional platform modules keep
        // their existing independent zero-probe contract.
        if ($requiredChecks === [] && in_array(
            config('enterprise_modules.modules.'.$code.'.category'),
            ['operations', 'finance', 'analytics'], true,
        )) {
            return 'unhealthy';
        }

        // Empty/unconfigured probes are not a successful execution of the
        // health checks bound into an exact-version signed module manifest.
        // In particular Procurement declares 'database' and must really
        // execute that check before its operational HTTP link becomes ready.
        $passedChecks = [];
        foreach ($health->checks as $check) {
            // The report is memoized. Any probe expiring since aggregation
            // degrades the module, even if it is an additional configured probe.
            if ($check->isStale()) {
                return 'unhealthy';
            }

            if ($check->status === ModuleHealthStatus::HEALTHY) {
                $passedChecks[] = $check->key;
            }
        }

        if (array_diff($requiredChecks, $passedChecks) !== []) {
            return 'unhealthy';
        }

        // A health probe may take time while another request suspends the
        // module. Re-read persisted lifecycle after probing: a healthy report
        // must never revive the earlier ENABLED snapshot or signed proof.
        try {
            $registration = $this->registry->registration($code);
            if ($registration === null
                || $registration->manifest_version !== $manifestVersion
                || $registration->getRawOriginal('state') !== ModuleState::ENABLED->value) {
                return 'uncertified';
            }

            // A concurrent operator or a probe may alter the installed
            // manifest during health evaluation. Do not reuse the signature
            // checked before the probe for a changed permissions/health scope.
            $stored = $registration->getAttribute('manifest');
            if (! is_array($stored)) {
                return 'uncertified';
            }

            $approvedManifest = $this->manifests->require($code);
            $approvedDigest = ModuleWorkflowAttestation::manifestSha256($approvedManifest);
            $storedDigest = ModuleWorkflowAttestation::manifestSha256(ModuleManifest::fromArray($stored));
            if ($approvedDigest === null || $storedDigest === null
                || ! hash_equals($approvedDigest, $storedDigest)) {
                return 'uncertified';
            }
        } catch (Throwable) {
            return 'uncertified';
        }

        // Health probes are application code and may revoke a certification
        // while they run. A previously verified signature is not a perpetual
        // grant: re-read the certification and attestation before returning
        // READY, just as we re-read the persisted lifecycle and manifest.
        if (config('enterprise_module_readiness.certified_versions.'.$code) !== $manifestVersion
            || ! $this->hasWorkflowEvidence($code, $manifestVersion)) {
            return 'uncertified';
        }

        return 'ready';
    }

    /**
     * Workflow evidence is read from mutable application configuration.
     *
     * @phpstan-impure
     */
    private function hasWorkflowEvidence(string $code, int $manifestVersion): bool
    {
        $category = config('enterprise_modules.modules.'.$code.'.category');
        $required = config('enterprise_module_readiness.required_workflows', []);

        // A missing, malformed or unreviewed manifest category must not
        // downgrade an ERP module to optional and evade workflow proof.
        if (! is_string($category) || ! in_array($category, [
            'operations', 'finance', 'analytics', 'platform', 'restaurant',
        ], true)) {
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

        try {
            $manifest = $this->manifests->require($code);
        } catch (Throwable) {
            // A missing/malformed canonical manifest is never an approval.
            return false;
        }

        if ($manifest->version !== $manifestVersion) {
            return false;
        }

        $expectedDigest = ModuleWorkflowAttestation::manifestSha256($manifest);
        $signedDigest = $attestation['manifest_sha256'] ?? null;
        if ($expectedDigest === null
            || ! is_string($signedDigest)
            || ! hash_equals($expectedDigest, $signedDigest)) {
            return false;
        }

        // The persisted installation must match the current source manifest
        // and its version. A stale installation row cannot reuse a signed
        // certificate for another manifest merely because ENABLED remains set.
        try {
            $registration = $this->registry->registration($code);
            // Never trust only the caller-supplied lifecycle snapshot.
            // A concurrent admin may have suspended/disabled the persisted
            // ERP module after that snapshot while its attestation remains
            // cryptographically valid for the unchanged manifest version.
            if ($registration === null
                || $registration->manifest_version !== $manifestVersion
                || $registration->getRawOriginal('state') !== ModuleState::ENABLED->value) {
                return false;
            }

            // Model property metadata may describe the storage column as
            // JSON text; read the cast value, then validate its real type.
            $storedManifest = $registration->getAttribute('manifest');
            if (! is_array($storedManifest)) {
                return false;
            }

            $installedManifest = ModuleManifest::fromArray($storedManifest);
            $installedDigest = ModuleWorkflowAttestation::manifestSha256($installedManifest);
            if ($installedManifest->code !== $code
                || $installedManifest->version !== $manifestVersion
                || $installedDigest === null
                || ! hash_equals($expectedDigest, $installedDigest)) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $payload = ModuleWorkflowAttestation::payload($code, $manifestVersion, $steps, $attestation);

        return $payload !== null && ModuleWorkflowAttestation::hasTrustedSignature($attestation, $payload);
    }
}
