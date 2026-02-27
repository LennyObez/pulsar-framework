<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

use function is_array;
use function is_float;
use function is_int;
use function is_string;

/**
 * Form submission pipeline configuration.
 *
 * @psalm-api Public configuration DTO loaded from config/cms.php; consumed
 *            by FormSubmissionService and SpamScorer setup.
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
            spamThreshold: is_float($data['spam_threshold'] ?? null) ? $data['spam_threshold'] : (float) 5.0,
            rateLimitPerHour: is_int($data['rate_limit_per_hour'] ?? null) ? $data['rate_limit_per_hour'] : 10,
            notificationRecipients: is_array($data['notification_recipients'] ?? null) ? array_values(array_map(static fn(mixed $v): string => is_string($v) ? $v : '', $data['notification_recipients'])) : [],
            honeypotFieldName: is_string($data['honeypot_field_name'] ?? null) ? $data['honeypot_field_name'] : '_hp_field',
            powDifficulty: is_string($data['pow_difficulty'] ?? null) ? $data['pow_difficulty'] : '0000',
        );
    }
}
