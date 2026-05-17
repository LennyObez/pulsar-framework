<?php

declare(strict_types=1);

namespace Pulsar\Database\Seeder;

use Pulsar\Api\Api;

/**
 * Represents a discovered seeder file.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SeederEntry
{
    public function __construct(
        public string $name,
        public string $path,
    ) {}
}
