<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;

use function count;
use function in_array;
use function memory_get_usage;

/**
 * CI gate that validates memory stability across many requests.
 *
 * Boots the application once, sends N deterministic requests, and
 * checks that memory growth stays within configured thresholds.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class LeakSentinel
{
    /**
     * @param list<ServerRequestInterface> $fixtureRequests Deterministic request fixtures to cycle through
     */
    public function __construct(
        private array $fixtureRequests,
    ) {}

    /**
     * Run the leak sentinel check.
     *
     * @param callable(ServerRequestInterface): void $handler Function that processes a request (e.g., kernel->handle())
     */
    public function run(
        callable $handler,
        LeakSentinelConfig $config,
    ): LeakSentinelReport {
        /** @var array<int, int> $snapshots */
        $snapshots = [];
        $fixtureCount = count($this->fixtureRequests);

        for ($i = 1; $i <= $config->totalRequests; $i++) {
            // Cycle through fixture requests deterministically
            $request = $this->fixtureRequests[($i - 1) % $fixtureCount];

            $handler($request);

            // Take memory snapshot at configured points
            if (in_array($i, $config->snapshotPoints, true)) {
                $snapshots[$i] = memory_get_usage(true);
            }
        }

        return LeakSentinelReport::fromSnapshots(
            $snapshots,
            $config->growthPercentThreshold,
            $config->growthBytesThreshold,
        );
    }
}
