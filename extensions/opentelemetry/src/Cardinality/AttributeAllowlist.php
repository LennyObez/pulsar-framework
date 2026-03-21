<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Cardinality;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;

use function array_intersect_key;
use function array_key_exists;
use function array_map;
use function sprintf;

/**
 * Filters metric attributes to only allowed keys per scope.
 *
 * Prevents cardinality explosion by restricting which attribute keys
 * are forwarded for each metric scope. Unknown keys are logged on first
 * occurrence and tracked in a bounded set.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AttributeAllowlist
{
    private OverflowTracker $unknownTracker;

    /** @var array<string, array<string, int>> Pre-computed allowlist lookup tables */
    private array $allowedLookup;

    /**
     * @param array<string, list<string>> $allowedKeys Mapping of scope name to allowed attribute keys
     * @param int $maxTrackedUnknowns Maximum number of unknown keys to track
     */
    public function __construct(
        array $allowedKeys,
        int $maxTrackedUnknowns = 1000,
        private ?LoggerInterface $logger = null,
    ) {
        /** @var array<string, array<string, int>> $lookup */
        $lookup = array_map(array_flip(...), $allowedKeys);
        $this->allowedLookup = $lookup;
        $this->unknownTracker = new OverflowTracker($maxTrackedUnknowns);
    }

    /**
     * Filter attributes to only allowed keys for the given scope.
     *
     * If the scope has no allowlist configured, all attributes pass through.
     *
     * @template TValue
     * @param array<string, TValue> $attributes
     * @return array<string, TValue>
     */
    public function filter(string $scope, array $attributes): array
    {
        if (!array_key_exists($scope, $this->allowedLookup)) {
            return $attributes;
        }

        $allowed = $this->allowedLookup[$scope];
        $filtered = array_intersect_key($attributes, $allowed);

        foreach ($attributes as $key => $value) {
            if (!array_key_exists($key, $allowed)) {
                $this->logUnknownKey($scope, $key);
            }
        }

        return $filtered;
    }

    private function logUnknownKey(string $scope, string $key): void
    {
        if ($this->unknownTracker->track($key)) {
            $this->logger?->debug(sprintf(
                'Unknown attribute key "%s" in scope "%s": filtered out',
                $key,
                $scope,
            ));
        }
    }
}
