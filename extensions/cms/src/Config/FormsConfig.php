<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

/**
 * Form submission pipeline configuration.
 */
#[Api(since: '1.0.0')]
final readonly class FormsConfig
{
    /**
     * @param float $spamThreshold Aggregated spam score threshold for classification
     * @param int $rateLimitPerHour Maximum submissions per IP per hour
     * @param list<string> $notificationRecipients Email addresses for submission notifications
     * @param string $honeypotFieldName Hidden field name for bot detection
     * @param string $powDifficulty Proof-of-work hash prefix difficulty
     */
    public function __construct(
        public float $spamThreshold = 5.0,
        public int $rateLimitPerHour = 10,
        public array $notificationRecipients = [],
        public string $honeypotFieldName = '_hp_field',
        public string $powDifficulty = '0000',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            spamThreshold: (float) ($data['spam_threshold'] ?? 5.0),
            rateLimitPerHour: (int) ($data['rate_limit_per_hour'] ?? 10),
            notificationRecipients: (array) ($data['notification_recipients'] ?? []),
            honeypotFieldName: (string) ($data['honeypot_field_name'] ?? '_hp_field'),
            powDifficulty: (string) ($data['pow_difficulty'] ?? '0000'),
        );
    }
}
