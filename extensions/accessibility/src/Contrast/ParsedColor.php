<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Contrast;

use Pulsar\Api\Api;

use function sprintf;

/**
 * Normalized RGB color with alpha channel.
 */
#[Api(since: '1.0.0')]
final readonly class ParsedColor
{
    public function __construct(
        public int $r,
        public int $g,
        public int $b,
        public float $alpha = 1.0,
    ) {}

    /**
     * Returns the color as a 6-digit hex string (e.g., #ff00aa).
     */
    public function toHex(): string
    {
        return sprintf('#%02x%02x%02x', $this->r, $this->g, $this->b);
    }
}
