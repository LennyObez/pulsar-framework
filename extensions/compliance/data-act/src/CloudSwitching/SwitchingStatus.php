<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\CloudSwitching;

use Pulsar\Api\Api;

/**
 * Status of a cloud switching migration plan.
 * @api
 */
#[Api(since: '1.0.0')]
enum SwitchingStatus: string
{
    /** Plan created, migration not yet started. */
    case Initiated = 'initiated';

    /** Data export in progress. */
    case Exporting = 'exporting';

    /** Data transferred, customer verifying at target. */
    case Transferring = 'transferring';

    /** Migration completed successfully. */
    case Completed = 'completed';

    /** Migration cancelled by the customer. */
    case Cancelled = 'cancelled';
}
