<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * CMS workflow notification configuration.
 *
 * @psalm-api Public configuration DTO loaded from config/cms.php; consumed
 *            by CmsNotificationDispatcher.
 * @api
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
     * @param array{
     *     enabled?: bool|int|string,
     *     channels?: list<string>,
     *     notify_on_publish?: bool|int|string,
     *     notify_on_review?: bool|int|string,
     *     notify_on_comment?: bool|int|string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            channels: Coerce::listOfString($data['channels'] ?? null, ['log']),
            notifyOnPublish: (bool) ($data['notify_on_publish'] ?? true),
            notifyOnReview: (bool) ($data['notify_on_review'] ?? true),
            notifyOnComment: (bool) ($data['notify_on_comment'] ?? true),
        );
    }
}
