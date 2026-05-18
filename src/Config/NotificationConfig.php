<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/notification.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class NotificationConfig
{
    /**
     * @param list<NotificationChannelType> $defaultChannels Default channels when notification does not specify via()
     * @param string|null $unsubscribeUrlPattern URL pattern with {notifiable_id} and {channel} placeholders for RFC 8058 headers
     * @param string|null $fcmServerKey Legacy FCM server key (deprecated; use fcmProjectId + fcmOAuthToken)
     * @param string|null $fcmProjectId Firebase project ID for FCM v1 API
     * @param string|null $fcmOAuthToken OAuth2 Bearer token for FCM v1 API authentication
     */
    public function __construct(
        public bool $enabled = false,
        public array $defaultChannels = [],
        public int $rateLimitPerMinute = 60,
        public bool $regulated = false,
        public bool $auditHashEnabled = false,
        public ?string $unsubscribeUrlPattern = null,
        public ?string $fcmServerKey = null,
        public ?string $fcmProjectId = null,
        public ?string $fcmOAuthToken = null,
    ) {}

    /**
     * @param array{
     *     enabled?: bool,
     *     default_channels?: list<string>,
     *     rate_limit_per_minute?: int,
     *     regulated?: bool,
     *     audit_hash_enabled?: bool,
     *     unsubscribe_url_pattern?: string|null,
     *     fcm_server_key?: string|null,
     *     fcm_project_id?: string|null,
     *     fcm_oauth_token?: string|null,
     * } $data Raw array from config/notification.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('NOTIFICATION_ENABLED') !== null
            ? $environment->get('NOTIFICATION_ENABLED') === 'true'
            : ($data['enabled'] ?? false);

        $defaultChannels = [];
        foreach ($data['default_channels'] ?? [] as $channel) {
            $type = NotificationChannelType::tryFrom($channel);
            if ($type !== null) {
                $defaultChannels[] = $type;
            }
        }

        $rawRateLimit = $environment->get('NOTIFICATION_RATE_LIMIT');
        $rateLimitPerMinute = $rawRateLimit !== null ? (int) $rawRateLimit : ($data['rate_limit_per_minute'] ?? 60);

        $regulated = $environment->get('NOTIFICATION_REGULATED') !== null
            ? $environment->get('NOTIFICATION_REGULATED') === 'true'
            : ($data['regulated'] ?? false);

        $auditHashEnabled = $environment->get('NOTIFICATION_AUDIT_HASH') !== null
            ? $environment->get('NOTIFICATION_AUDIT_HASH') === 'true'
            : ($data['audit_hash_enabled'] ?? false);

        return new self(
            enabled: $enabled,
            defaultChannels: $defaultChannels,
            rateLimitPerMinute: $rateLimitPerMinute > 0 ? $rateLimitPerMinute : 60,
            regulated: $regulated,
            auditHashEnabled: $auditHashEnabled,
            unsubscribeUrlPattern: $environment->get('NOTIFICATION_UNSUBSCRIBE_URL') ?? $data['unsubscribe_url_pattern'] ?? null,
            fcmServerKey: $environment->get('NOTIFICATION_FCM_SERVER_KEY') ?? $data['fcm_server_key'] ?? null,
            fcmProjectId: $environment->get('NOTIFICATION_FCM_PROJECT_ID') ?? $data['fcm_project_id'] ?? null,
            fcmOAuthToken: $environment->get('NOTIFICATION_FCM_OAUTH_TOKEN') ?? $data['fcm_oauth_token'] ?? null,
        );
    }
}
