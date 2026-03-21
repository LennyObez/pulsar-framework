<?php

declare(strict_types=1);

namespace Pulsar\Http;

use Pulsar\Api\Api;

/**
 * Represents a single value from an Accept-style header with quality factor.
 *
 * Used internally by ContentNegotiation for sorting and matching preferences.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AcceptValue
{
    /**
     * @param string $value The media type, language, or encoding value
     * @param float $quality The q-factor (0.0 to 1.0, default 1.0)
     * @param int $order Position in the original header (for stable sort tiebreaking)
     * @param array<string, string> $parameters Additional parameters (e.g. charset, level)
     */
    public function __construct(
        public string $value,
        public float $quality = 1.0,
        public int $order = 0,
        public array $parameters = [],
    ) {}
}
