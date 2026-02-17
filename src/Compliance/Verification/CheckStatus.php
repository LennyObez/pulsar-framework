<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use Pulsar\Api\Api;

/**
 * Status of a compliance check execution.
 */
#[Api(since: '1.0.0')]
enum CheckStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Skip = 'skip';
}
