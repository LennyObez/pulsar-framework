<?php

declare(strict_types=1);

namespace Pulsar\Build;

use Pulsar\Api\Api;

/**
 * Status of a single artifact integrity check.
 * @api
 */
#[Api(since: '1.0.0')]
enum VerificationStatus: string
{
    case Ok = 'ok';
    case Modified = 'modified';
    case Missing = 'missing';
    case Extra = 'extra';
}
