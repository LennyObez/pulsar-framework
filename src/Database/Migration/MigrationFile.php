<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

/**
 * Readonly value object for a discovered migration file on disk.
 */
readonly class MigrationFile
{
    public function __construct(
        public string $version,
        public string $name,
        public string $path,
    ) {}
}
