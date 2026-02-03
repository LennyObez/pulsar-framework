<?php

declare(strict_types=1);

namespace Pulsar\Config;

/**
 * Typed DTO for a single logging channel configuration.
 */
readonly class LoggingChannelConfig
{
    public function __construct(
        public string $name,
        public string $driver,
        public ?string $path = null,
        public ?string $stream = null,
    ) {}
}
