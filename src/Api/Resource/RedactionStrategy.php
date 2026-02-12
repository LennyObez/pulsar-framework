<?php

declare(strict_types=1);

namespace Pulsar\Api\Resource;

use Pulsar\Api\Api;

/**
 * Strategies for redacting field values when the requester has partial access.
 */
#[Api(since: '1.0.0')]
enum RedactionStrategy: string
{
    /** Replace value with a fixed mask string (e.g., "***") */
    case Mask = 'mask';

    /** Truncate value to a specified length with ellipsis */
    case Truncate = 'truncate';

    /** Replace value with a one-way hash */
    case Hash = 'hash';

    /** Remove the field entirely from the response */
    case Omit = 'omit';
}
