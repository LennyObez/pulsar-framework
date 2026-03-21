<?php

declare(strict_types=1);

namespace Pulsar\Resilience;

use Closure;
use NoDiscard;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Config\RetryConfig;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;
use Throwable;

use function min;
use function sprintf;
use function usleep;

/**
 * Retry policy with exponential backoff and optional jitter.
 */
#[Api(since: '1.0.0')]
final readonly class RetryPolicy
{
    private Randomizer $randomizer;

    public function __construct(
        private int $maxAttempts,
        private int $baseDelayMs,
        private int $maxDelayMs,
        private float $multiplier,
        private bool $jitter,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Create a RetryPolicy from a RetryConfig DTO.
     */
    #[NoDiscard]
    public static function fromConfig(RetryConfig $config): self
    {
        return new self(
            maxAttempts: $config->maxAttempts,
            baseDelayMs: $config->baseDelayMs,
            maxDelayMs: $config->maxDelayMs,
            multiplier: $config->multiplier,
            jitter: $config->jitter,
        );
    }

    /**
     * Execute the given closure with retry logic.
     *
     * @template T
     * @param Closure(): T $operation
     * @param LoggerInterface|null $logger Optional logger for retry diagnostics
     * @return RetryResult
     *
     * @throws RandomException
     */
    public function execute(Closure $operation, ?LoggerInterface $logger = null): RetryResult
    {
        /** @var list<int> $attemptDelays */
        $attemptDelays = [];
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $result = $operation();

                if ($attempt > 1) {
                    $logger?->info(sprintf('Retry succeeded on attempt %d', $attempt));
                }

                return RetryResult::success($result, $attempt, $attemptDelays);
            } catch (Throwable $e) {
                $lastException = $e;

                if ($attempt === $this->maxAttempts) {
                    break;
                }

                $delay = $this->calculateDelay($attempt);
                $attemptDelays[] = $delay;

                $logger?->warning(sprintf(
                    'Attempt %d/%d failed: %s: retrying in %dms',
                    $attempt,
                    $this->maxAttempts,
                    $e->getMessage(),
                    $delay,
                ));

                usleep($delay * 1000);
            }
        }

        /** @var Throwable $lastException */
        $logger?->error(sprintf(
            'All %d retry attempts exhausted: %s',
            $this->maxAttempts,
            $lastException->getMessage(),
        ));

        return RetryResult::exhausted($this->maxAttempts, $lastException, $attemptDelays);
    }

    /**
     * Calculate delay in milliseconds for the given attempt number.
     *
     * Uses exponential backoff: baseDelay * multiplier^(attempt-1), capped at maxDelay.
     * When jitter is enabled, a random factor between 0.5 and 1.5 is applied.
     *
     * @param int $attempt The current attempt number (1-based)
     *
     * @throws RandomException
     */
    public function calculateDelay(int $attempt): int
    {
        $delay = (int) ((float) $this->baseDelayMs * ($this->multiplier ** (float) ($attempt - 1)));
        $delay = min($delay, $this->maxDelayMs);

        if ($this->jitter) {
            /** @var int<0, max> $randomRange */
            $randomRange = (int) ((float) $delay * 0.5);

            if ($randomRange > 0) {
                $jitterAmount = $this->randomizer->getInt(0, $randomRange * 2) - $randomRange;
                $delay = max(1, $delay + $jitterAmount);
            }
        }

        return $delay;
    }
}
