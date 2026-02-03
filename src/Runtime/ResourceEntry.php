<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Pulsar\Api\Internal;

/**
 * Tracked resource entry for leak detection.
 */
#[Internal]
final readonly class ResourceEntry
{
    public function __construct(
        public string $id,
        public string $type,
        public string $description,
        public float $trackedAt,
    ) {}
}
