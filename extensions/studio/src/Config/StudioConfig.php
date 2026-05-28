<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Config\Environment;
use Pulsar\Support\Coerce;

use function in_array;
use function max;
use function min;

/**
 * Typed configuration DTO for config/studio.php.
 */
#[Internal]
final readonly class StudioConfig
{
    /**
     * @param string $storeBackend One of 'sqlite', 'database', or 'buffered'
     */
    public function __construct(
        public bool $enabled = true,
        public string $storagePath = 'storage/studio/studio.sqlite',
        public string $storeBackend = 'sqlite',
        public StudioRetentionConfig $retention = new StudioRetentionConfig(),
        public StudioSecurityConfig $security = new StudioSecurityConfig(),
        public StudioServerConfig $server = new StudioServerConfig(),
        public StudioCollectorConfig $collectors = new StudioCollectorConfig(),
        public float $samplingRate = 1.0,
    ) {}

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     storage_path?: string,
     *     store?: string,
     *     retention?: array<string, mixed>,
     *     security?: array<string, mixed>,
     *     server?: array<string, mixed>,
     *     collectors?: array<string, mixed>,
     *     sampling_rate?: float|int,
     * } $data Raw array from config/studio.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('STUDIO_ENABLED') !== null
            ? $environment->get('STUDIO_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? true);

        $storagePath = $environment->get('STUDIO_STORAGE_PATH')
            ?? $data['storage_path']
            ?? 'storage/studio/studio.sqlite';

        $storeBackendRaw = $environment->get('STUDIO_STORE_BACKEND') ?? $data['store'] ?? 'sqlite';
        $storeBackend = in_array($storeBackendRaw, ['sqlite', 'database', 'buffered'], true)
            ? $storeBackendRaw
            : 'sqlite';

        $samplingRateEnv = $environment->get('STUDIO_SAMPLING_RATE');
        $samplingRate = $samplingRateEnv !== null
            ? (float) $samplingRateEnv
            : Coerce::float($data['sampling_rate'] ?? null, 1.0);

        return new self(
            enabled: $enabled,
            storagePath: $storagePath,
            storeBackend: $storeBackend,
            retention: StudioRetentionConfig::fromArray($data['retention'] ?? [], $environment),
            security: StudioSecurityConfig::fromArray($data['security'] ?? [], $environment),
            server: StudioServerConfig::fromArray($data['server'] ?? [], $environment),
            collectors: StudioCollectorConfig::fromArray($data['collectors'] ?? [], $environment),
            samplingRate: max(0.0, min(1.0, $samplingRate)),
        );
    }
}
