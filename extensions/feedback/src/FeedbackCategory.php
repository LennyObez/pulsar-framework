<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback;

use Pulsar\Api\Api;

/**
 * Classification category for user feedback submissions.
 * @api
 */
#[Api(since: '1.0.0')]
enum FeedbackCategory: string
{
    case Bug = 'bug';
    case Feature = 'feature';
    case Improvement = 'improvement';
    case Question = 'question';
    case Other = 'other';
}
