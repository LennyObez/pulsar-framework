<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Monitoring;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Psd2\Contracts\VelocityTrackerInterface;
use Pulsar\Extension\Psd2\Domain\VelocityWindow;

use function array_filter;
use function array_sum;
use function count;
use function time;

/**
 * In-memory velocity tracker for development and testing.
 */
#[Internal(reason: 'Use VelocityTrackerInterface for production implementations')]
final class InMemoryVelocityTracker implements VelocityTrackerInterface
{
    /**
     * @var array<string, list<array{amount: int, currency: string, timestamp: int}>>
     */
    private array $records = [];

    #[Override]
    public function record(string $identityId, int $amountMinorUnits, string $currency): void
    {
        $this->records[$identityId][] = [
            'amount' => $amountMinorUnits,
            'currency' => $currency,
            'timestamp' => time(),
        ];
    }

    #[Override]
    public function getWindow(string $identityId, int $windowSeconds, string $currency): VelocityWindow
    {
        $cutoff = time() - $windowSeconds;
        $entries = $this->records[$identityId] ?? [];

        $matching = array_filter(
            $entries,
            static fn(array $entry): bool => $entry['timestamp'] >= $cutoff && $entry['currency'] === $currency,
        );

        $amounts = array_map(static fn(array $entry): int => $entry['amount'], $matching);

        return new VelocityWindow(
            identityId: $identityId,
            windowSeconds: $windowSeconds,
            transactionCount: count($matching),
            totalAmountMinorUnits: (int) array_sum($amounts),
            currency: $currency,
        );
    }
}
