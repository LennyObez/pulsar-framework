<?php

declare(strict_types=1);

namespace Pulsar\Integrity;

use Pulsar\Api\Api;

/**
 * Status of a single file during integrity verification.
 */
#[Api(since: '1.0.0')]
enum FileVerificationStatus: string
{
    case Verified = 'verified';
    case Modified = 'modified';
    case Missing = 'missing';
    case Added = 'added';
}
