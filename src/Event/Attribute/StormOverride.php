<?php

declare(strict_types=1);

namespace Pulsar\Event\Attribute;

use Attribute;
use Pulsar\Api\Api;

use function max;
use function min;

/**
 * Overrides the default storm protection maxDepth for a specific event class.
 *
 * Use this for events that legitimately need deeper dispatch chains
 * than the global maximum (default 32). Value is clamped to [1, 256].
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class StormOverride
{
    public int $maxDepth;

    public function __construct(
        int $maxDepth,
    ) {
        $this->maxDepth = max(1, min(256, $maxDepth));
    }
}
