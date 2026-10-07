<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Typed DTO for a single logging channel configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class LoggingChannelConfig
{
    public function __construct(
        public string $name,
        public string $driver,
        public ?string $path = null,
        public ?string $stream = null,
    ) {}
}
