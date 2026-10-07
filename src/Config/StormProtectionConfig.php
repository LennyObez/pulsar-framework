<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function max;
use function min;

/**
 * Storm protection sub-configuration for event dispatching.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StormProtectionConfig implements ReportsUnknownKeys
{
    /** Keys read from the `storm_protection` sub-array of config/event.php. */
    private const array KNOWN_KEYS = ['max_depth', 'loop_detection', 'max_repeats_per_event'];

    private const int MIN_DEPTH = 1;
    private const int MAX_DEPTH_CEILING = 1000;
    private const int MIN_REPEATS = 1;
    private const int MAX_REPEATS_CEILING = 100;

    /**
     * @param list<string> $unknownKeys Keys present in the raw `storm_protection` array
     *     that this DTO does not read — a misspelled `loop_detection` reads as the
     *     default rather than the operator's choice, on the guard that stops an event
     *     cascade from recursing without bound.
     */
    public function __construct(
        public int $maxDepth = 32,
        public bool $loopDetection = true,
        public int $maxRepeatsPerEvent = 3,
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

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
        $maxDepth = Coerce::int($data['max_depth'] ?? null, 32);
        $loopDetection = Coerce::strictBool($data['loop_detection'] ?? null, true);
        $maxRepeats = Coerce::int($data['max_repeats_per_event'] ?? null, 3);

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
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
