<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function max;
use function min;

/**
 * Storm protection sub-configuration for event dispatching.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StormProtectionConfig
{
    private const int MIN_DEPTH = 1;
    private const int MAX_DEPTH_CEILING = 1000;
    private const int MIN_REPEATS = 1;
    private const int MAX_REPEATS_CEILING = 100;

    public function __construct(
        public int $maxDepth = 32,
        public bool $loopDetection = true,
        public int $maxRepeatsPerEvent = 3,
    ) {}

    /**
     * @param array{
     *     max_depth?: int,
     *     loop_detection?: bool,
     *     max_repeats_per_event?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data, ?Environment $environment = null): self
    {
        $maxDepth = $data['max_depth'] ?? 32;
        $loopDetection = $data['loop_detection'] ?? true;
        $maxRepeats = $data['max_repeats_per_event'] ?? 3;

        // Environment variable overrides (applied after array, before clamping)
        if ($environment !== null) {
            $envMaxDepth = $environment->get('EVENT_STORM_MAX_DEPTH');
            if ($envMaxDepth !== null) {
                $maxDepth = (int) $envMaxDepth;
            }

            $envLoopDetection = $environment->get('EVENT_STORM_LOOP_DETECTION');
            if ($envLoopDetection !== null) {
                $loopDetection = $envLoopDetection === 'true';
            }

            $envMaxRepeats = $environment->get('EVENT_STORM_MAX_REPEATS');
            if ($envMaxRepeats !== null) {
                $maxRepeats = (int) $envMaxRepeats;
            }
        }

        return new self(
            maxDepth: self::clamp($maxDepth, self::MIN_DEPTH, self::MAX_DEPTH_CEILING),
            loopDetection: $loopDetection,
            maxRepeatsPerEvent: self::clamp($maxRepeats, self::MIN_REPEATS, self::MAX_REPEATS_CEILING),
        );
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
