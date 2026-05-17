<?php

declare(strict_types=1);

namespace Pulsar\Notification;

use Pulsar\Api\Api;

/**
 * DTO representing a push notification message.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PushMessage
{
    /**
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array<string, string> $data Custom data payload
     * @param string|null $imageUrl Optional image URL
     * @param string|null $clickAction Optional click action / deep link
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $data = [],
        public ?string $imageUrl = null,
        public ?string $clickAction = null,
    ) {}
}
