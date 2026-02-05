<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use Pulsar\Api\Api;

/**
 * Readonly value object for a discovered migration file on disk.
 */
#[Api]
readonly class MigrationFile
{
    public function __construct(
        public string $version,
        public string $name,
        public string $path,
    ) {}
}
