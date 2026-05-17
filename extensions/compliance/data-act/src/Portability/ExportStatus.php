<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Portability;

use Pulsar\Api\Api;

/**
 * Status of a data portability export request.
 * @api
 */
#[Api(since: '1.0.0')]
enum ExportStatus: string
{
    /** Request submitted, awaiting processing. */
    case Pending = 'pending';

    /** Export is being generated. */
    case Processing = 'processing';

    /** Export completed and data is available. */
    case Fulfilled = 'fulfilled';

    /** Request was cancelled by the user. */
    case Cancelled = 'cancelled';

    /** Request failed due to an error. */
    case Failed = 'failed';
}
