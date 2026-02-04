<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use Pulsar\Api\Api;

/**
 * Readonly value object for a discovered migration file on disk.
 */
#[Api(since: '1.0.0')]
readonly class MigrationFile
{
    public function __construct(
        public string $version,
        public string $name,
        public string $path,
    ) {}
}
