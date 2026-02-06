<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function is_float;
use function is_int;

use NoDiscard;
use Pulsar\Api\Internal;

/**
 * Typed configuration DTO for config/studio.php.
 */
#[Internal]
readonly class StudioConfig
{
    public function __construct(
        public bool $enabled = true,
        public string $storagePath = 'storage/studio/studio.sqlite',
        public StudioRetentionConfig $retention = new StudioRetentionConfig(),
        public StudioSecurityConfig $security = new StudioSecurityConfig(),
        public StudioServerConfig $server = new StudioServerConfig(),
        public StudioCollectorConfig $collectors = new StudioCollectorConfig(),
        public float $samplingRate = 1.0,
    ) {}

    /**
     * @param array<string, mixed> $data Raw array from config/studio.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('STUDIO_ENABLED') !== null
            ? $environment->get('STUDIO_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? true);

        /** @var string $storagePath */
        $storagePath = $environment->get('STUDIO_STORAGE_PATH')
            ?? ($data['storage_path'] ?? 'storage/studio/studio.sqlite');

        /** @var array<string, mixed> $retentionData */
        $retentionData = $data['retention'] ?? [];

        /** @var array<string, mixed> $securityData */
        $securityData = $data['security'] ?? [];

        /** @var array<string, mixed> $serverData */
        $serverData = $data['server'] ?? [];

        /** @var array<string, mixed> $collectorData */
        $collectorData = $data['collectors'] ?? [];

        $samplingRateEnv = $environment->get('STUDIO_SAMPLING_RATE');
        if ($samplingRateEnv !== null) {
            $samplingRate = (float) $samplingRateEnv;
        } else {
            $rawSamplingRate = $data['sampling_rate'] ?? 1.0;
            $samplingRate = is_float($rawSamplingRate) || is_int($rawSamplingRate) ? (float) $rawSamplingRate : (is_numeric($rawSamplingRate) ? (float) $rawSamplingRate : 1.0);
        }

        return new self(
            enabled: $enabled,
            storagePath: $storagePath,
            retention: StudioRetentionConfig::fromArray($retentionData, $environment),
            security: StudioSecurityConfig::fromArray($securityData, $environment),
            server: StudioServerConfig::fromArray($serverData, $environment),
            collectors: StudioCollectorConfig::fromArray($collectorData, $environment),
            samplingRate: max(0.0, min(1.0, $samplingRate)),
        );
    }
}
