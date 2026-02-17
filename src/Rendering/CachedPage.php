<?php

declare(strict_types=1);

namespace Pulsar\Rendering;

use Pulsar\Api\Internal;

/**
 * Internal cached page entry for ISR.
 */
#[Internal]
final readonly class CachedPage
{
    public function __construct(
        public string $html,
        public int $generatedAt,
    ) {}
}
