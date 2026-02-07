<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Typed configuration DTO for `config/notification.php`.
 */
#[Api(since: '1.0.0')]
readonly class NotificationConfig
{
    /**
     * @param list<NotificationChannelType> $defaultChannels Default channels when notification does not specify via()
     * @param string|null $unsubscribeUrlPattern URL pattern with {notifiable_id} and {channel} placeholders for RFC 8058 headers
     */
    public function __construct(
        public bool $enabled = false,
        public array $defaultChannels = [],
        public int $rateLimitPerMinute = 60,
        public bool $regulated = false,
        public bool $auditHashEnabled = false,
        public ?string $unsubscribeUrlPattern = null,
    ) {}

    /**
     * @param array<string, mixed> $data Raw array from config/notification.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('NOTIFICATION_ENABLED') !== null
            ? $environment->get('NOTIFICATION_ENABLED') === 'true'
            : (is_bool($data['enabled'] ?? null) ? $data['enabled'] : false);

        $defaultChannels = [];
        /** @var mixed $rawChannels */
        $rawChannels = $data['default_channels'] ?? [];

        if (is_array($rawChannels)) {
            foreach ($rawChannels as $channel) {
                if (is_string($channel)) {
                    $type = NotificationChannelType::tryFrom($channel);

                    if ($type !== null) {
                        $defaultChannels[] = $type;
                    }
                }
            }
        }

        $rawRateLimit = $environment->get('NOTIFICATION_RATE_LIMIT')
            ?? ($data['rate_limit_per_minute'] ?? 60);
        $rateLimitPerMinute = is_int($rawRateLimit) ? $rawRateLimit : (is_string($rawRateLimit) ? (int) $rawRateLimit : 60);

        $regulated = $environment->get('NOTIFICATION_REGULATED') !== null
            ? $environment->get('NOTIFICATION_REGULATED') === 'true'
            : (is_bool($data['regulated'] ?? null) ? $data['regulated'] : false);

        $auditHashEnabled = $environment->get('NOTIFICATION_AUDIT_HASH') !== null
            ? $environment->get('NOTIFICATION_AUDIT_HASH') === 'true'
            : (is_bool($data['audit_hash_enabled'] ?? null) ? $data['audit_hash_enabled'] : false);

        $unsubscribeUrlPattern = $environment->get('NOTIFICATION_UNSUBSCRIBE_URL')
            ?? (is_string($data['unsubscribe_url_pattern'] ?? null) ? $data['unsubscribe_url_pattern'] : null);

        return new self(
            enabled: $enabled,
            defaultChannels: $defaultChannels,
            rateLimitPerMinute: $rateLimitPerMinute > 0 ? $rateLimitPerMinute : 60,
            regulated: $regulated,
            auditHashEnabled: $auditHashEnabled,
            unsubscribeUrlPattern: $unsubscribeUrlPattern,
        );
    }
}
