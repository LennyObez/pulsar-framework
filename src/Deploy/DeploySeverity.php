<?php

declare(strict_types=1);

namespace Pulsar\Deploy;

use Pulsar\Api\Internal;

/**
 * Configurable severity level for deploy checks.
 *
 * Maps config values ('fail', 'warn', 'off') to deploy check behavior.
 */
#[Internal]
enum DeploySeverity: string
{
    /** Check failures are blocking errors. */
    case Fail = 'fail';

    /** Check failures are non-blocking warnings. */
    case Warn = 'warn';

    /** Check is disabled entirely. */
    case Off = 'off';

    /**
     * Convert to the corresponding CheckSeverity for non-passing results.
     */
    public function toCheckSeverity(): CheckSeverity
    {
        return match ($this) {
            self::Fail => CheckSeverity::Error,
            self::Warn => CheckSeverity::Warning,
            self::Off => CheckSeverity::Pass,
        };
    }
}
