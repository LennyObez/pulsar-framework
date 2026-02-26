<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback;

use Pulsar\Api\Api;

/**
 * Lifecycle status for a feedback item during admin triage.
 */
#[Api(since: '1.0.0')]
enum FeedbackStatus: string
{
    case Received = 'received';
    case Investigating = 'investigating';
    case Resolved = 'resolved';
    case WontFix = 'wont_fix';
    case Duplicate = 'duplicate';
}
