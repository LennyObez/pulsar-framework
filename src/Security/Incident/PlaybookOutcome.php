<?php

declare(strict_types=1);

namespace Pulsar\Security\Incident;

use Pulsar\Api\Api;

/**
 * Outcome classification for playbook execution.
 */
#[Api(since: '1.0.0')]
enum PlaybookOutcome: string
{
    /** All steps executed successfully. */
    case Completed = 'completed';

    /** A step returned false, halting the chain early. */
    case Halted = 'halted';

    /** No playbook was registered for the threat category. */
    case NoPlaybook = 'no_playbook';

    /** A step threw an exception. */
    case Error = 'error';
}
