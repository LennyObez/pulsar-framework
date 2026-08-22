<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * EU Data Act (Regulation 2023/2854) extension configuration.
 *
 * Configures data portability formats, interoperability profiles,
 * cloud switching timelines, and third-party access policies.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DataActConfig
{
    /**
     * @param bool   $enabled                Whether Data Act compliance features are active
     * @param string $entityRole             Role: data_holder, data_recipient, cloud_provider
     * @param int    $portabilityMaxDays     Max days to fulfill a portability request (Art. 5)
     * @param int    $switchingTransitionDays Max transition period for cloud switching in days (Art. 25)
     * @param string $defaultExportFormat    Default format for data export (e.g., JSON, CSV, XML)
     * @param bool   $enableAccessLogging    Whether to log all data access events
     * @param int    $accessLogRetentionDays How many days to retain data access audit logs
     */
    public function __construct(
        public bool $enabled = false,
        public string $entityRole = 'data_holder',
        public int $portabilityMaxDays = 30,
        public int $switchingTransitionDays = 30,
        public string $defaultExportFormat = 'json',
        public bool $enableAccessLogging = true,
        public int $accessLogRetentionDays = 1825,
    ) {}

    /**
     * @param array{
     *     enabled?: bool,
     *     entity_role?: string,
     *     portability_max_days?: int,
     *     switching_transition_days?: int,
     *     default_export_format?: string,
     *     enable_access_logging?: bool,
     *     access_log_retention_days?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null),
            entityRole: Coerce::string($data['entity_role'] ?? null, 'data_holder'),
            portabilityMaxDays: Coerce::int($data['portability_max_days'] ?? null, 30),
            switchingTransitionDays: Coerce::int($data['switching_transition_days'] ?? null, 30),
            defaultExportFormat: Coerce::string($data['default_export_format'] ?? null, 'json'),
            enableAccessLogging: Coerce::strictBool($data['enable_access_logging'] ?? null, true),
            accessLogRetentionDays: Coerce::int($data['access_log_retention_days'] ?? null, 1825),
        );
    }

    /**
     * Whether the entity is a data holder (Art. 4-5 obligations).
     */
    #[NoDiscard]
    public function isDataHolder(): bool
    {
        return $this->entityRole === 'data_holder';
    }

    /**
     * Whether the entity is a cloud service provider (Art. 23-26 obligations).
     */
    #[NoDiscard]
    public function isCloudProvider(): bool
    {
        return $this->entityRole === 'cloud_provider';
    }

    /**
     * Whether the entity is a data recipient (Art. 6 obligations).
     */
    #[NoDiscard]
    public function isDataRecipient(): bool
    {
        return $this->entityRole === 'data_recipient';
    }
}
