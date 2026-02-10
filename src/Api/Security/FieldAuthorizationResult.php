<?php

declare(strict_types=1);

namespace Pulsar\Api\Security;

use Pulsar\Api\Api;

/**
 * Result of a field-level authorization check.
 */
#[Api(since: '1.0.0')]
enum FieldAuthorizationResult
{
    /** Full access — include the field as-is */
    case Allowed;

    /** Partial access — include the field with redaction */
    case Redacted;

    /** No access — omit the field entirely */
    case Denied;
}
