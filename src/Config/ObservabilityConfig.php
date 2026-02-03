<?php

declare(strict_types=1);

namespace Pulsar\Config;

/**
 * Typed configuration DTO for `config/observability.php`.
 *
 * Environment variables `LOG_LEVEL` and `LOG_CHANNEL` override file values.
 */
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
        $defaultChannel = $environment->get('LOG_CHANNEL')
            ?? (string) ($logging['default_channel'] ?? 'file'); // @phpstan-ignore cast.string

        $level = $environment->get('LOG_LEVEL')
            ?? (string) ($logging['level'] ?? 'info'); // @phpstan-ignore cast.string

        // Build channel DTOs
        /** @var array<string, array<string, mixed>> $channels */
        $channels = $logging['channels'] ?? [];
        $channelConfigs = [];

        foreach ($channels as $name => $channelData) {
            $channelConfigs[] = new LoggingChannelConfig(
                name: $name,
                driver: (string) ($channelData['driver'] ?? 'file'), // @phpstan-ignore cast.string
                path: isset($channelData['path']) ? (string) $channelData['path'] : null, // @phpstan-ignore cast.string
                stream: isset($channelData['stream']) ? (string) $channelData['stream'] : null, // @phpstan-ignore cast.string
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

        return new self(
            defaultLoggingChannel: $defaultChannel,
            loggingLevel: $level,
            loggingChannels: $channelConfigs,
            metrics: $metricsConfig,
            tracing: $tracingConfig,
            errorTracking: $errorTrackingConfig,
        );
    }
}
