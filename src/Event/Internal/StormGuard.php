<?php

declare(strict_types=1);

namespace Pulsar\Event\Internal;

use Pulsar\Api\Internal;
use Pulsar\Config\StormProtectionConfig;
use Pulsar\Event\Exception\EventException;

use function array_pop;

/**
 * Tracks event dispatch depth and detects dispatch loops.
 *
 * Provides two protection mechanisms:
 * 1. maxDepth: hard ceiling on total dispatch chain length (regardless of event types)
 * 2. Loop detection: count-based — if the same event FQCN appears maxRepeatsPerEvent
 *    times anywhere in the current chain, throws EventException
 */
#[Internal]
final class StormGuard
{
    private int $currentDepth = 0;

    /** @var list<string> */
    private array $dispatchChain = [];

    public function __construct(
        private readonly StormProtectionConfig $config,
    ) {}

    /**
     * Enter a dispatch frame for the given event class.
     *
     * @throws EventException On depth exceeded or loop detected
     */
    public function enter(string $eventClass, ?int $overrideMaxDepth = null): void
    {
        $newDepth = $this->currentDepth + 1;
        $maxDepth = $overrideMaxDepth ?? $this->config->maxDepth;

        if ($newDepth > $maxDepth) {
            throw EventException::stormDetected($eventClass, $newDepth, $maxDepth);
        }

        if ($this->config->loopDetection) {
            $occurrences = 0;

            foreach ($this->dispatchChain as $chainEntry) {
                if ($chainEntry === $eventClass) {
                    $occurrences++;
                }
            }

            // The new entry would make occurrences + 1
            if ($occurrences + 1 >= $this->config->maxRepeatsPerEvent) {
                throw EventException::loopDetected($eventClass, $occurrences + 1, $this->config->maxRepeatsPerEvent);
            }
        }

        $this->currentDepth = $newDepth;
        $this->dispatchChain[] = $eventClass;
    }

    /**
     * Leave the current dispatch frame.
     */
    public function leave(): void
    {
        if ($this->currentDepth > 0) {
            $this->currentDepth--;
            array_pop($this->dispatchChain);
        }
    }

    /**
     * Get the current dispatch depth.
     */
    public function currentDepth(): int
    {
        return $this->currentDepth;
    }

    /**
     * Get the current dispatch chain.
     *
     * @return list<string>
     */
    public function dispatchChain(): array
    {
        return $this->dispatchChain;
    }

    /**
     * Reset the guard state (for testing or worker reuse).
     */
    public function reset(): void
    {
        $this->currentDepth = 0;
        $this->dispatchChain = [];
    }
}
