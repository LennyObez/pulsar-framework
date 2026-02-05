<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function is_string;

use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/observability.php`.
 *
 * Environment variables `LOG_LEVEL` and `LOG_CHANNEL` override file values.
 */
#[Api]
readonly class ObservabilityConfig
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
     * @param array<string, mixed> $data Raw array from config/observability.php
     */
    public static function fromArray(array $data, Environment $environment): self
    {
        /** @var array<string, mixed> $logging */
        $logging = $data['logging'] ?? [];

        // Env overrides for channel and level
        $rawDefaultChannel = $logging['default_channel'] ?? 'file';
        $defaultChannel = $environment->get('LOG_CHANNEL')
            ?? (is_string($rawDefaultChannel) ? $rawDefaultChannel : 'file');

        $rawLevel = $logging['level'] ?? 'info';
        $level = $environment->get('LOG_LEVEL')
            ?? (is_string($rawLevel) ? $rawLevel : 'info');

        // Build channel DTOs
        /** @var array<string, array<string, mixed>> $channels */
        $channels = $logging['channels'] ?? [];
        $channelConfigs = [];

        foreach ($channels as $name => $channelData) {
            $rawDriver = $channelData['driver'] ?? 'file';
            $rawPath = $channelData['path'] ?? null;
            $rawStream = $channelData['stream'] ?? null;
            $channelConfigs[] = new LoggingChannelConfig(
                name: $name,
                driver: is_string($rawDriver) ? $rawDriver : 'file',
                path: is_string($rawPath) ? $rawPath : null,
                stream: is_string($rawStream) ? $rawStream : null,
            );
        }

        // Metrics config
        /** @var array<string, mixed> $metricsData */
        $metricsData = $data['metrics'] ?? [];
        $metricsConfig = MetricsConfig::fromArray($metricsData);

        // Tracing config
        /** @var array<string, mixed> $tracingData */
        $tracingData = $data['tracing'] ?? [];
        $tracingConfig = TracingConfig::fromArray($tracingData);

        // Error tracking config
        /** @var array<string, mixed> $errorTrackingData */
        $errorTrackingData = $data['error_tracking'] ?? [];
        $errorTrackingConfig = ErrorTrackingConfig::fromArray($errorTrackingData);

        // Build audit config
        /** @var array<string, mixed> $auditData */
        $auditData = $data['audit'] ?? [];
        $auditConfig = AuditConfig::fromArray($auditData, $environment);

        return new self(
            defaultLoggingChannel: $defaultChannel,
            loggingLevel: $level,
            loggingChannels: $channelConfigs,
            metrics: $metricsConfig,
            tracing: $tracingConfig,
            errorTracking: $errorTrackingConfig,
            audit: $auditConfig,
        );
    }
}
