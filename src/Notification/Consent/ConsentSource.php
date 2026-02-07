<?php

declare(strict_types=1);

namespace Pulsar\Notification\Consent;

use Pulsar\Api\Api;

/**
 * Source of a consent action.
 */
#[Api(since: '1.0.0')]
enum ConsentSource: string
{
    case UserAction = 'user_action';
    case Api = 'api';
    case Import = 'import';
}
