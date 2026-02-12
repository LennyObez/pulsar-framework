<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Workflow;

use Pulsar\Api\Api;

/**
 * Status of an editorial review for content in the editorial workflow.
 */
#[Api(since: '1.0.0')]
enum ReviewStatus: string
{
    case Pending = 'pending';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
