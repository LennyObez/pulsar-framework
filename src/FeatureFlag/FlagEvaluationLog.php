<?php

declare(strict_types=1);

namespace Pulsar\FeatureFlag;

use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Runtime\ResettableInterface;
use Throwable;

use function array_filter;
use function array_values;
use function count;

/**
 * In-memory log of feature flag evaluations.
 */
final class FlagEvaluationLog implements FlagEvaluationLogInterface, ResettableInterface
{
    /** @var list<FlagEvaluation> */
    private array $evaluations = [];

    /** @var list<callable(FlagEvaluation): void> */
    private array $observers = [];

    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    /**
     * Record a flag evaluation.
     */
    public function record(FlagEvaluation $evaluation): void
    {
        $this->evaluations[] = $evaluation;

        foreach ($this->observers as $observer) {
            try {
                $observer($evaluation);
            } catch (Throwable $observerException) {
                // Observer failures must not poison the recording path.
                // Surface the error through the injected logger instead of
                // swallowing it silently (C-4).
                $this->logger?->error('Feature flag evaluation observer failed', [
                    'flag' => $evaluation->flagName,
                    'exception' => $observerException,
                ]);
            }
        }
    }

    /**
     * Register an observer to be notified on every flag evaluation.
     *
     * @param callable(FlagEvaluation): void $observer
     */
    #[Override]
    public function addObserver(callable $observer): void
    {
        $this->observers[] = $observer;
    }

    /**
     * Get all recorded evaluations.
     *
     * @return list<FlagEvaluation>
     */
    public function all(): array
    {
        return $this->evaluations;
    }

    /**
     * Get evaluations for a specific flag.
     *
     * @return list<FlagEvaluation>
     */
    public function forFlag(string $flagName): array
    {
        return array_values(array_filter(
            $this->evaluations,
            static fn(FlagEvaluation $e): bool => $e->flagName === $flagName,
        ));
    }

    /**
     * Clear all recorded evaluations.
     */
    public function clear(): void
    {
        $this->evaluations = [];
    }

    /**
     * Get the number of recorded evaluations.
     */
    public function count(): int
    {
        return count($this->evaluations);
    }

    #[Override]
    public function resetRequestState(): void
    {
        $this->clear();
    }
}
