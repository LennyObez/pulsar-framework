<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Mail\Webhook\MailWebhookConfig;
use Pulsar\Support\Coerce;

use function is_array;

/**
 * Typed configuration DTO for `config/mail.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MailConfig implements ReportsUnknownKeys
{
    /** Keys recognised in config/mail.php. */
    private const array KNOWN_KEYS = [
        'enabled', 'default_driver', 'default_from_address', 'default_from_name', 'default_reply_to',
        'driver_options', 'encryption_policy', 'hipaa_mode', 'audit_hash_enabled', 'webhooks',
    ];

    /**
     * @param array<string, mixed> $driverOptions Driver-specific configuration (host, port, credentials, etc.)
     */
    public function __construct(
        public bool $enabled = false,
        public MailDriverType $defaultDriver = MailDriverType::Smtp,
        public string $defaultFromAddress = '',
        public string $defaultFromName = '',
        public string $defaultReplyTo = '',
        public MailEncryptionPolicy $encryptionPolicy = MailEncryptionPolicy::None,
        public bool $hipaaMode = false,
        public bool $auditHashEnabled = false,
        public array $driverOptions = [],
        public MailWebhookConfig $webhooks = new MailWebhookConfig(),
        /** @var list<string> */
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     default_driver?: string,
     *     default_from_address?: string,
     *     default_from_name?: string,
     *     default_reply_to?: string,
     *     encryption_policy?: string,
     *     hipaa_mode?: bool|int|string,
     *     audit_hash_enabled?: bool|int|string,
     *     driver_options?: array<string, mixed>,
     *     webhooks?: array<string, mixed>,
     * } $data Raw array from config/mail.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $webhooks = $data['webhooks'] ?? null;
        $enabled = $environment->get('MAIL_ENABLED') !== null
            ? $environment->get('MAIL_ENABLED') === 'true'
            : Coerce::strictBool($data['enabled'] ?? null);

        $driverEnv = $environment->get('MAIL_DRIVER');
        $driverValue = $driverEnv ?? Coerce::string($data['default_driver'] ?? null, 'smtp');
        $defaultDriver = MailDriverType::tryFrom($driverValue) ?? MailDriverType::Smtp;

        $encryptionEnv = $environment->get('MAIL_ENCRYPTION_POLICY');
        $encryptionValue = $encryptionEnv ?? Coerce::string($data['encryption_policy'] ?? null, 'none');
        $encryptionPolicy = MailEncryptionPolicy::tryFrom($encryptionValue) ?? MailEncryptionPolicy::None;

        $hipaaMode = $environment->get('MAIL_HIPAA_MODE') !== null
            ? $environment->get('MAIL_HIPAA_MODE') === 'true'
            : Coerce::strictBool($data['hipaa_mode'] ?? null);

        $auditHashEnabled = $environment->get('MAIL_AUDIT_HASH') !== null
            ? $environment->get('MAIL_AUDIT_HASH') === 'true'
            : Coerce::strictBool($data['audit_hash_enabled'] ?? null);

        $fromAddressEnv = $environment->get('MAIL_FROM_ADDRESS');
        $fromNameEnv = $environment->get('MAIL_FROM_NAME');
        $replyToEnv = $environment->get('MAIL_REPLY_TO');
        $driverOptions = $data['driver_options'] ?? null;

        return new self(
            enabled: $enabled,
            defaultDriver: $defaultDriver,
            defaultFromAddress: $fromAddressEnv ?? Coerce::string($data['default_from_address'] ?? null),
            defaultFromName: $fromNameEnv ?? Coerce::string($data['default_from_name'] ?? null),
            defaultReplyTo: $replyToEnv ?? Coerce::string($data['default_reply_to'] ?? null),
            encryptionPolicy: $encryptionPolicy,
            hipaaMode: $hipaaMode,
            auditHashEnabled: $auditHashEnabled,
            driverOptions: is_array($driverOptions) ? $driverOptions : [],
            webhooks: MailWebhookConfig::fromArray(is_array($webhooks) ? $webhooks : []),
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
