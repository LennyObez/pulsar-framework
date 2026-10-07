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
 * Both failure paths report a failed verification rather than a passing one. A
 * dashboard that shows green because no manifest was loaded, or because the
 * verifier threw, states that the tree is intact on the strength of never having
 * looked at it — which is worse than showing nothing.
 */
#[Internal(reason: 'Adapter wiring; not part of public API')]
final readonly class CoreIntegrityVerificationAdapter implements IntegrityVerificationRunnerInterface
{
    /**
     * @param IntegrityManifest|null $manifest Null when the composition root
     *        could not produce an authentic manifest, which is itself a failure.
     */
    public function __construct(
        private ManifestVerifierInterface $verifier,
        private ?IntegrityManifest $manifest = null,
    ) {}

    #[Override]
    public function run(): VerificationResult
    {
        if ($this->manifest === null) {
            return self::unverified();
        }

        try {
            return $this->verifier->verify($this->manifest);
        } catch (Throwable) {
            return self::unverified();
        }
    }

    /**
     * The result for "nothing was checked": failed, with nothing verified.
     */
    private static function unverified(): VerificationResult
    {
        return new VerificationResult(
            passed: false,
            verified: 0,
            modified: 0,
            missing: 0,
            added: 0,
            files: [],
        );
    }
}
