<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

/**
 * Outcome of an auditable event.
 */
enum AuditOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Denied = 'denied';
    case Error = 'error';
}
