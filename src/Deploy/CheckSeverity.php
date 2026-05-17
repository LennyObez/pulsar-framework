<?php

declare(strict_types=1);

namespace Pulsar\Deploy;

use Pulsar\Api\Api;

/**
 * Severity level for a deploy check result.
 * @api
 */
#[Api(since: '1.0.0')]
enum CheckSeverity: string
{
    case Pass = 'pass';
    case Warning = 'warning';
    case Error = 'error';

    /**
     * Whether this severity indicates a passing check.
     */
    public function isPassing(): bool
    {
        return $this === self::Pass;
    }

    /**
     * Whether this severity indicates a failure (warning or error).
     */
    public function isFailure(): bool
    {
        return $this !== self::Pass;
    }
}
