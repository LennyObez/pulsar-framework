<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/observability.php`.
 *
 * Environment variables `LOG_LEVEL` and `LOG_CHANNEL` override file values.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ObservabilityConfig
{
    /**
     * @param list<LoggingChannelConfig> $loggingChannels
     */
    public function __construct(
        public string $defaultLoggingChannel,
        public string $loggingLevel,
        public array $loggingChannels,
        public MetricsConfig $metrics = new MetricsConfig(),
        public TracingConfig $tracing = new TracingConfig(),
        public ErrorTrackingConfig $errorTracking = new ErrorTrackingConfig(),
        public AuditConfig $audit = new AuditConfig(),
    ) {}

    /**
     * Build from the raw observability config array and environment.
     *
     * @param array{
     *     logging?: array{
     *         default_channel?: string,
     *         level?: string,
     *         channels?: array<string, array{
     *             driver?: string,
     *             path?: string|null,
     *             stream?: string|null,
     *         }>,
     *     },
     *     metrics?: array<string, mixed>,
     *     tracing?: array<string, mixed>,
     *     error_tracking?: array<string, mixed>,
     *     audit?: array<string, mixed>,
     * } $data Raw array from config/observability.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $logging = $data['logging'] ?? [];

        $defaultChannel = $environment->get('LOG_CHANNEL') ?? $logging['default_channel'] ?? 'file';
        $level = $environment->get('LOG_LEVEL') ?? $logging['level'] ?? 'info';

        $channelConfigs = [];
        foreach ($logging['channels'] ?? [] as $name => $channelData) {
            $channelConfigs[] = new LoggingChannelConfig(
                name: $name,
                driver: $channelData['driver'] ?? 'file',
                path: $channelData['path'] ?? null,
                stream: $channelData['stream'] ?? null,
            );
        }

        /** @var array<string, mixed> $auditData */
        $auditData = $data['audit'] ?? [];

        return new self(
            defaultLoggingChannel: $defaultChannel,
            loggingLevel: $level,
            loggingChannels: $channelConfigs,
            metrics: MetricsConfig::fromArray($data['metrics'] ?? []),
            tracing: TracingConfig::fromArray($data['tracing'] ?? []),
            errorTracking: ErrorTrackingConfig::fromArray($data['error_tracking'] ?? []),
            audit: AuditConfig::fromArray($auditData, $environment),
        );
    }
}
