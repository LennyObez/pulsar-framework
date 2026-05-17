<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Domain;

use Pulsar\Api\Api;

/**
 * Thread content type: categorizes the purpose of a discussion thread.
 * @api
 */
#[Api(since: '1.0.0')]
enum ThreadType: string
{
    case Discussion = 'discussion';
    case Question = 'question';
    case BugReport = 'bug_report';
    case FeatureRequest = 'feature_request';
    case Showcase = 'showcase';
    case Announcement = 'announcement';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Discussion => 'Discussion',
            self::Question => 'Question',
            self::BugReport => 'Bug Report',
            self::FeatureRequest => 'Feature Request',
            self::Showcase => 'Showcase',
            self::Announcement => 'Announcement',
        };
    }

    /**
     * Whether this thread type supports marking a post as the accepted solution.
     */
    public function supportsSolution(): bool
    {
        return match ($this) {
            self::Question, self::BugReport => true,
            default => false,
        };
    }
}
