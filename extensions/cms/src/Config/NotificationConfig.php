<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

/**
 * CMS workflow notification configuration.
 */
#[Api(since: '1.0.0')]
final readonly class NotificationConfig
{
    /**
     * @param bool $enabled Master switch for CMS workflow notifications
     * @param list<string> $channels Delivery channels: 'log', 'email', 'database'
     * @param bool $notifyOnPublish Send notification when content is published
     * @param bool $notifyOnReview Send notification when review is requested
     * @param bool $notifyOnComment Send notification when a comment is posted
     */
    public function __construct(
        public bool $enabled = false,
        public array $channels = ['log'],
        public bool $notifyOnPublish = true,
        public bool $notifyOnReview = true,
        public bool $notifyOnComment = true,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            channels: (array) ($data['channels'] ?? ['log']),
            notifyOnPublish: (bool) ($data['notify_on_publish'] ?? true),
            notifyOnReview: (bool) ($data['notify_on_review'] ?? true),
            notifyOnComment: (bool) ($data['notify_on_comment'] ?? true),
        );
    }
}
