<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;

use function array_values;
use function memory_get_usage;
use function sprintf;

/**
 * Per-request resource and memory leak detector.
 *
 * Tracks explicitly registered resources and monitors memory growth.
 * Emits warnings (not errors) when leaks are detected.
 */
#[Internal]
final class LeakDetector
{
    private int $memoryBaseline = 0;

    /** @var array<string, ResourceEntry> */
    private array $trackedResources = [];

    /**
     * @param int $memoryGrowthThreshold Maximum allowed memory growth in bytes before warning
     */
    public function __construct(
        private readonly int $memoryGrowthThreshold = 2_097_152,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Snapshot memory baseline and clear tracked resources.
     */
    public function beginRequest(): void
    {
        $this->memoryBaseline = memory_get_usage(true);
        $this->trackedResources = [];
    }

    /**
     * Register a resource for tracking.
     */
    public function trackResource(string $id, string $type, string $description): void
    {
        $this->trackedResources[$id] = new ResourceEntry(
            id: $id,
            type: $type,
            description: $description,
            trackedAt: microtime(true),
        );
    }

    /**
     * Mark a tracked resource as released.
     */
    public function releaseResource(string $id): void
    {
        unset($this->trackedResources[$id]);
    }

    /**
     * Check for unreleased resources and memory growth.
     *
     * @return list<string> Warning messages (empty if no leaks detected)
     */
    public function endRequest(): array
    {
        $warnings = [];

        // Check unreleased resources
        if ($this->trackedResources !== []) {
            foreach ($this->trackedResources as $entry) {
                $message = sprintf(
                    'Unreleased resource: [%s] %s — %s',
                    $entry->type,
                    $entry->id,
                    $entry->description,
                );
                $warnings[] = $message;
                $this->logger?->warning($message);
            }
        }

        // Check memory growth
        $currentMemory = memory_get_usage(true);
        $growth = $currentMemory - $this->memoryBaseline;

        if ($growth > $this->memoryGrowthThreshold) {
            $message = sprintf(
                'Memory growth detected: %d bytes (threshold: %d bytes)',
                $growth,
                $this->memoryGrowthThreshold,
            );
            $warnings[] = $message;
            $this->logger?->warning($message);
        }

        return $warnings;
    }

    /**
     * Get the memory baseline snapshot.
     */
    public function memoryBaseline(): int
    {
        return $this->memoryBaseline;
    }

    /**
     * Get currently tracked resources.
     *
     * @return list<ResourceEntry>
     */
    public function trackedResources(): array
    {
        return array_values($this->trackedResources);
    }
}
