<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tracing\Sampling;

use Override;
use Pulsar\Api\Api;
use Pulsar\Observability\Tracing\TraceContext;

use function hrtime;
use function min;
use function sprintf;

/**
 * Token bucket sampler that limits the rate of sampled traces.
 *
 * Refills tokens at a constant rate and allows bursts up to maxBurst.
 * Uses monotonic hrtime for accurate timing.
 * @api
 */
#[Api(since: '1.0.0')]
final class RateLimitedSampler implements SamplerInterface
{
    private float $tokens;
    private int $lastRefillTime;
    private readonly string $reason;

    public function __construct(
        private readonly float $ratePerSecond,
        private readonly float $maxBurst = 0.0,
    ) {
        $effectiveMaxBurst = $this->maxBurst > 0.0 ? $this->maxBurst : $this->ratePerSecond;
        $this->tokens = $effectiveMaxBurst;
        $this->lastRefillTime = hrtime(true);
        $this->reason = sprintf('rate_limited(%s/s)', $this->ratePerSecond);
    }

    #[Override]
    public function shouldSample(TraceContext $context): SamplingDecision
    {
        $this->refillTokens();

        if ($this->tokens >= 1.0) {
            $this->tokens -= 1.0;

            return new SamplingDecision(
                sampled: true,
                reason: $this->reason,
            );
        }

        return new SamplingDecision(
            sampled: false,
            reason: $this->reason,
        );
    }

    private function refillTokens(): void
    {
        $now = hrtime(true);
        $elapsedNanos = $now - $this->lastRefillTime;
        $this->lastRefillTime = $now;

        $elapsedSeconds = (float) $elapsedNanos / 1_000_000_000.0;
        $newTokens = $elapsedSeconds * $this->ratePerSecond;

        $effectiveMaxBurst = $this->maxBurst > 0.0 ? $this->maxBurst : $this->ratePerSecond;
        $this->tokens = min($this->tokens + $newTokens, $effectiveMaxBurst);
    }
}
