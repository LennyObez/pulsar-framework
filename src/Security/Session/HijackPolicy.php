<?php

declare(strict_types=1);

namespace Pulsar\Security\Session;

use Pulsar\Api\Api;

/**
 * Policy for handling IP address changes detected mid-session.
 */
#[Api(since: '1.0.0')]
enum HijackPolicy: string
{
    /** Immediately invalidate the session. */
    case Invalidate = 'invalidate';

    /** Present an MFA challenge before continuing. */
    case Challenge = 'challenge';

    /** Log a warning but allow the session to continue. */
    case Warn = 'warn';
}
