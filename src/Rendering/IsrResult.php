<?php

declare(strict_types=1);

namespace Pulsar\Rendering;

use Pulsar\Api\Api;

/**
 * Result from an ISR page lookup.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class IsrResult
{
    public function __construct(
        public string $html,
        public bool $hit,
        public bool $stale,
        public string $path,
    ) {}
}
