<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use Pulsar\Api\Api;

/**
 * Outcome of an auditable event.
 */
#[Api]
enum AuditOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Denied = 'denied';
    case Error = 'error';
}
