<?php

declare(strict_types=1);

namespace Pulsar\Security\Session;

use Pulsar\Api\Api;

/**
 * Action recommended by the hijack detector.
 * @api
 */
#[Api(since: '1.0.0')]
enum HijackAction: string
{
    /** No hijacking detected: allow the request. */
    case Allow = 'allow';

    /** Session should be invalidated immediately. */
    case Invalidate = 'invalidate';

    /** Require MFA re-authentication before continuing. */
    case Challenge = 'challenge';

    /** Log warning but allow the request to proceed. */
    case Warn = 'warn';
}
