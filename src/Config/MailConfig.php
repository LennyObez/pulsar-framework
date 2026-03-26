<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/mail.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MailConfig
{
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
    ) {}

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
     * } $data Raw array from config/mail.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('MAIL_ENABLED') !== null
            ? $environment->get('MAIL_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        $driverValue = $environment->get('MAIL_DRIVER') ?? $data['default_driver'] ?? 'smtp';
        $defaultDriver = MailDriverType::tryFrom($driverValue) ?? MailDriverType::Smtp;

        $encryptionValue = $environment->get('MAIL_ENCRYPTION_POLICY') ?? $data['encryption_policy'] ?? 'none';
        $encryptionPolicy = MailEncryptionPolicy::tryFrom($encryptionValue) ?? MailEncryptionPolicy::None;

        $hipaaMode = $environment->get('MAIL_HIPAA_MODE') !== null
            ? $environment->get('MAIL_HIPAA_MODE') === 'true'
            : (bool) ($data['hipaa_mode'] ?? false);

        $auditHashEnabled = $environment->get('MAIL_AUDIT_HASH') !== null
            ? $environment->get('MAIL_AUDIT_HASH') === 'true'
            : (bool) ($data['audit_hash_enabled'] ?? false);

        return new self(
            enabled: $enabled,
            defaultDriver: $defaultDriver,
            defaultFromAddress: $environment->get('MAIL_FROM_ADDRESS') ?? $data['default_from_address'] ?? '',
            defaultFromName: $environment->get('MAIL_FROM_NAME') ?? $data['default_from_name'] ?? '',
            defaultReplyTo: $environment->get('MAIL_REPLY_TO') ?? $data['default_reply_to'] ?? '',
            encryptionPolicy: $encryptionPolicy,
            hipaaMode: $hipaaMode,
            auditHashEnabled: $auditHashEnabled,
            driverOptions: $data['driver_options'] ?? [],
        );
    }
}
