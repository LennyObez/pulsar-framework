<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\ContinuousVerification\Internal;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\ContinuousVerification\ContinuousVerificationInterface;
use Pulsar\Security\ZeroTrust\Policy\PolicyEngineInterface;
use Pulsar\Security\ZeroTrust\Policy\PolicyEvaluationResult;
use Pulsar\Security\ZeroTrust\Signal\SignalContext;
use Pulsar\Security\ZeroTrust\Signal\SignalProviderInterface;

/**
 * Re-evaluates zero-trust claims at configurable intervals during sessions.
 *
 * Collects fresh signals from all providers and passes them through the policy
 * engine to detect trust degradation mid-session. The caller is responsible
 * for checking whether re-verification is needed based on timing.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Session-level verification, wired by composition root')]
final class ContinuousVerificationManager implements ContinuousVerificationInterface
{
    /** @var array<string, DateTimeImmutable> Last verification time per session */
    private array $lastVerificationTimes = [];

    /**
     * @param list<SignalProviderInterface> $signalProviders
     */
    public function __construct(
        private readonly array $signalProviders,
        private readonly PolicyEngineInterface $policyEngine,
        private readonly int $intervalSeconds,
    ) {}

    /**
     * Check whether re-verification is needed for the given session.
     */
    public function needsReverification(string $sessionId, DateTimeImmutable $now = new DateTimeImmutable()): bool
    {
        $lastTime = $this->lastVerificationTimes[$sessionId] ?? null;

        if ($lastTime === null) {
            return true;
        }

        $elapsed = $now->getTimestamp() - $lastTime->getTimestamp();

        return $elapsed >= $this->intervalSeconds;
    }

    /**
     * Re-evaluate the trust posture for the current context.
     *
     * Collects fresh signals, evaluates policy, and records the verification time.
     */
    #[Override]
    public function verify(SignalContext $context, string $resource, string $action): PolicyEvaluationResult
    {
        $claims = new ClaimSet();

        foreach ($this->signalProviders as $provider) {
            $claims = $claims->merge($provider->evaluate($context));
        }

        $result = $this->policyEngine->evaluate($claims, $resource, $action);

        if ($context->sessionId !== '') {
            $this->lastVerificationTimes[$context->sessionId] = new DateTimeImmutable();
        }

        return $result;
    }

    /**
     * Clear tracked verification time for a session (e.g., on logout).
     */
    public function clearSession(string $sessionId): void
    {
        unset($this->lastVerificationTimes[$sessionId]);
    }
}
