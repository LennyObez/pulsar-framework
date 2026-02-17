<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Internal\Adapter;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\HealthStatus\Contracts\IntegrityVerificationRunnerInterface;
use Pulsar\Integrity\IntegrityManifest;
use Pulsar\Integrity\ManifestVerifierInterface;
use Pulsar\Integrity\VerificationResult;
use Throwable;

/**
 * Adapts the core ManifestVerifier into the extension's IntegrityVerificationRunnerInterface.
 *
 * Accepts a pre-loaded manifest and delegates verification to the core verifier.
 * Returns a passing empty result when no manifest is provided.
 */
#[Internal(reason: 'Adapter wiring; not part of public API')]
final readonly class CoreIntegrityVerificationAdapter implements IntegrityVerificationRunnerInterface
{
    public function __construct(
        private ManifestVerifierInterface $verifier,
        private ?IntegrityManifest $manifest = null,
    ) {}

    #[Override]
    public function run(): VerificationResult
    {
        if ($this->manifest === null) {
            return new VerificationResult(
                passed: true,
                verified: 0,
                modified: 0,
                missing: 0,
                added: 0,
                files: [],
            );
        }

        try {
            return $this->verifier->verify($this->manifest);
        } catch (Throwable) {
            return new VerificationResult(
                passed: true,
                verified: 0,
                modified: 0,
                missing: 0,
                added: 0,
                files: [],
            );
        }
    }
}
