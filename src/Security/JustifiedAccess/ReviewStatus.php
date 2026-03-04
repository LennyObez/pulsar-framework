<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess;

use Pulsar\Api\Api;

/**
 * Status of a justification record in the compliance review workflow.
 */
#[Api(since: '1.0.0')]
enum ReviewStatus: string
{
    /** Awaiting compliance officer review. */
    case Pending = 'pending';

    /** Reviewed and approved by a compliance officer. */
    case Approved = 'approved';

    /** Flagged for further investigation. */
    case Flagged = 'flagged';

    /** Reviewed but not requiring further action. */
    case Reviewed = 'reviewed';

    /** Escalated to senior management or legal. */
    case Escalated = 'escalated';
}
