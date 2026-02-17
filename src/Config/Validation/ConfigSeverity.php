<?php

declare(strict_types=1);

namespace Pulsar\Config\Validation;

use Pulsar\Api\Api;

/**
 * Severity level for configuration validation issues.
 */
#[Api(since: '1.0.0')]
enum ConfigSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';

    public function isError(): bool
    {
        return $this === self::Error;
    }
}
