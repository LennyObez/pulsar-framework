<?php

declare(strict_types=1);

namespace Pulsar\DataProtection\Dsar;

use Pulsar\Api\Api;

/**
 * Status of a Data Subject Access Request.
 * @api
 */
#[Api(since: '1.0.0')]
enum DsarStatus: string
{
    /** Request submitted, awaiting identity verification. */
    case Pending = 'pending';

    /** Identity verified, data collection in progress. */
    case Processing = 'processing';

    /** Data package assembled, ready for download. */
    case Completed = 'completed';

    /** Request rejected (e.g., failed identity verification). */
    case Rejected = 'rejected';

    /** Data package downloaded by the subject. */
    case Downloaded = 'downloaded';
}
