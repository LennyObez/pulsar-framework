<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use Pulsar\Api\Api;

/**
 * A single file entry within an integrity manifest.
 */
#[Api(since: '1.0.0')]
final readonly class ManifestEntry
{
    public function __construct(
        public string $path,
        public string $hash,
        public int $size,
    ) {}
}
