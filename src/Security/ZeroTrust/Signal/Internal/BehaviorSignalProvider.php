<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Signal\Internal;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Signal\BehaviorBaselineInterface;
use Pulsar\Security\ZeroTrust\Signal\SignalContext;
use Pulsar\Security\ZeroTrust\Signal\SignalProviderInterface;

/**
 * Produces behavioral trust claims.
 *
 * Tracks request rates per identity and compares against established baselines
 * to detect anomalous activity patterns like rapid requests or deviation from
 * typical behavior.
 *
 * Claims produced:
 * - `behavior.rapid_requests` (bool): Whether the request rate exceeds the threshold
 * - `behavior.anomalous_pattern` (bool): Whether the request deviates from the user's baseline
 */
#[Internal]
final readonly class BehaviorSignalProvider implements SignalProviderInterface
{
    private const float CONFIDENCE_WITH_BASELINE = 0.8;
    private const float CONFIDENCE_WITHOUT_BASELINE = 0.3;

    /** Multiplier over baseline average that triggers rapid request detection. */
    private const float RAPID_REQUEST_MULTIPLIER = 3.0;

    /** Default max requests per minute when no baseline exists. */
    private const float DEFAULT_MAX_REQUESTS_PER_MINUTE = 60.0;

    public function __construct(
        private ?BehaviorBaselineInterface $baselineProvider = null,
        private float $rapidRequestMultiplier = self::RAPID_REQUEST_MULTIPLIER,
        private float $defaultMaxRequestsPerMinute = self::DEFAULT_MAX_REQUESTS_PER_MINUTE,
    ) {}

    public function evaluate(SignalContext $context): ClaimSet
    {
        $now = new DateTimeImmutable();
        $identityId = $context->identityId;

        if ($identityId === '') {
            return $this->anonymousClaims($now);
        }

        $baseline = $this->baselineProvider?->getBaseline($identityId);
        $hasBaseline = $baseline !== null;

        /** @var float|null $currentRequestRate */
        $currentRequestRate = $context->attribute('current_request_rate');

        $rapidRequests = $this->detectRapidRequests(
            $currentRequestRate,
            $hasBaseline ? $baseline->avgRequestsPerMinute : null,
        );

        $anomalous = false;

        if ($hasBaseline && $currentRequestRate !== null) {
            $anomalous = $this->detectAnomalousPattern($currentRequestRate, $baseline->avgRequestsPerMinute);
        }

        $confidence = $hasBaseline ? self::CONFIDENCE_WITH_BASELINE : self::CONFIDENCE_WITHOUT_BASELINE;

        return new ClaimSet([
            new Claim(
                name: 'behavior.rapid_requests',
                value: $rapidRequests,
                source: ClaimSource::BehaviorSignal,
                confidence: $confidence,
                timestamp: $now,
            ),
            new Claim(
                name: 'behavior.anomalous_pattern',
                value: $anomalous,
                source: ClaimSource::BehaviorSignal,
                confidence: $confidence,
                timestamp: $now,
            ),
        ]);
    }

    public function name(): string
    {
        return 'behavior';
    }

    private function detectRapidRequests(?float $currentRate, ?float $baselineRate): bool
    {
        if ($currentRate === null) {
            return false;
        }

        $threshold = $baselineRate !== null
            ? $baselineRate * $this->rapidRequestMultiplier
            : $this->defaultMaxRequestsPerMinute;

        return $currentRate > $threshold;
    }

    private function detectAnomalousPattern(float $currentRate, float $baselineRate): bool
    {
        if ($baselineRate <= 0.0) {
            return $currentRate > 0.0;
        }

        $deviation = abs($currentRate - $baselineRate) / $baselineRate;

        return $deviation > 2.0;
    }

    private function anonymousClaims(DateTimeImmutable $now): ClaimSet
    {
        return new ClaimSet([
            new Claim(
                name: 'behavior.rapid_requests',
                value: false,
                source: ClaimSource::BehaviorSignal,
                confidence: self::CONFIDENCE_WITHOUT_BASELINE,
                timestamp: $now,
            ),
            new Claim(
                name: 'behavior.anomalous_pattern',
                value: false,
                source: ClaimSource::BehaviorSignal,
                confidence: self::CONFIDENCE_WITHOUT_BASELINE,
                timestamp: $now,
            ),
        ]);
    }
}
