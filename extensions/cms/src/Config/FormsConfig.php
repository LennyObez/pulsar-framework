<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Form submission pipeline configuration.
 *
 * @psalm-api Public configuration DTO loaded from config/cms.php; consumed
 *            by FormSubmissionService and SpamScorer setup.
 * @api
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
     * @param array{
     *     spam_threshold?: float|int,
     *     rate_limit_per_hour?: int,
     *     notification_recipients?: list<string>,
     *     honeypot_field_name?: string,
     *     pow_difficulty?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            spamThreshold: Coerce::float($data['spam_threshold'] ?? null, 5.0),
            rateLimitPerHour: Coerce::int($data['rate_limit_per_hour'] ?? null, 10),
            notificationRecipients: Coerce::stringListOrEmpty($data['notification_recipients'] ?? null),
            honeypotFieldName: Coerce::string($data['honeypot_field_name'] ?? null, '_hp_field'),
            powDifficulty: Coerce::string($data['pow_difficulty'] ?? null, '0000'),
        );
    }
}
