<?php

declare(strict_types=1);

namespace Pulsar\Notification;

use Pulsar\Api\Api;

/**
 * DTO representing a Slack notification message.
 */
#[Api(since: '1.0.0')]
final readonly class SlackMessage
{
    /**
     * @param string        $channel   Slack channel or user (e.g., "#general", "@user")
     * @param string        $text      Message text (fallback for blocks)
     * @param list<array<string, mixed>> $blocks Slack Block Kit blocks
     * @param string|null   $username  Bot username override
     * @param string|null   $iconEmoji Bot icon emoji override (e.g., ":robot_face:")
     */
    public function __construct(
        public string $channel,
        public string $text,
        public array $blocks = [],
        public ?string $username = null,
        public ?string $iconEmoji = null,
    ) {}
}
