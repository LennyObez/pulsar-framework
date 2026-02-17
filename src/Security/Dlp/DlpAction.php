<?php

declare(strict_types=1);

namespace Pulsar\Security\Dlp;

use Pulsar\Api\Api;

/**
 * Action to take when sensitive data is detected.
 */
#[Api(since: '1.0.0')]
enum DlpAction: string
{
    /** Redact the sensitive data with a mask. */
    case Redact = 'redact';

    /** Block the response entirely. */
    case Block = 'block';

    /** Allow but generate an alert. */
    case Alert = 'alert';
}
