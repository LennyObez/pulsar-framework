<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_bool;
use function is_string;

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
     * @param array<string, mixed> $data Raw array from config/mail.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('MAIL_ENABLED') !== null
            ? $environment->get('MAIL_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        $driverValue = $environment->get('MAIL_DRIVER') ?? ($data['default_driver'] ?? 'smtp');
        $defaultDriver = MailDriverType::tryFrom(is_string($driverValue) ? $driverValue : 'smtp')
            ?? MailDriverType::Smtp;

        $defaultFromAddress = $environment->get('MAIL_FROM_ADDRESS')
            ?? (is_string($data['default_from_address'] ?? null) ? $data['default_from_address'] : '');

        $defaultFromName = $environment->get('MAIL_FROM_NAME')
            ?? (is_string($data['default_from_name'] ?? null) ? $data['default_from_name'] : '');

        $defaultReplyTo = $environment->get('MAIL_REPLY_TO')
            ?? (is_string($data['default_reply_to'] ?? null) ? $data['default_reply_to'] : '');

        $encryptionValue = $environment->get('MAIL_ENCRYPTION_POLICY') ?? ($data['encryption_policy'] ?? 'none');
        $encryptionPolicy = MailEncryptionPolicy::tryFrom(is_string($encryptionValue) ? $encryptionValue : 'none')
            ?? MailEncryptionPolicy::None;

        $hipaaMode = $environment->get('MAIL_HIPAA_MODE') !== null
            ? $environment->get('MAIL_HIPAA_MODE') === 'true'
            : (is_bool($data['hipaa_mode'] ?? null) ? $data['hipaa_mode'] : false);

        $auditHashEnabled = $environment->get('MAIL_AUDIT_HASH') !== null
            ? $environment->get('MAIL_AUDIT_HASH') === 'true'
            : (is_bool($data['audit_hash_enabled'] ?? null) ? $data['audit_hash_enabled'] : false);

        /** @var array<string, mixed> $driverOptions */
        $driverOptions = is_array($data['driver_options'] ?? null) ? $data['driver_options'] : [];

        return new self(
            enabled: $enabled,
            defaultDriver: $defaultDriver,
            defaultFromAddress: $defaultFromAddress,
            defaultFromName: $defaultFromName,
            defaultReplyTo: $defaultReplyTo,
            encryptionPolicy: $encryptionPolicy,
            hipaaMode: $hipaaMode,
            auditHashEnabled: $auditHashEnabled,
            driverOptions: $driverOptions,
        );
    }
}
