<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_string;

/**
 * Typed configuration DTO for `config/observability.php`.
 *
 * Environment variables `LOG_LEVEL` and `LOG_CHANNEL` override file values.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ObservabilityConfig implements ReportsUnknownKeys
{
    /** Keys recognised in config/observability.php. */
    private const array KNOWN_KEYS = ['logging', 'metrics', 'tracing', 'audit', 'error_tracking'];

    /**
     * Keys read from the `logging` sub-array. `driver`/`path`/`stream` are the flat
     * single-channel shape used when no `channels` map is given.
     */
    private const array KNOWN_LOGGING_KEYS = [
        'default_channel', 'level', 'channels', 'compliance', 'driver', 'path', 'stream',
    ];

    /** Keys read from a single entry of `logging.channels`. */
    private const array KNOWN_CHANNEL_KEYS = ['driver', 'path', 'stream'];

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
        public ComplianceLoggingConfig $complianceLogging = new ComplianceLoggingConfig(),
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
     * Build from the raw observability config array and environment.
     *
     * @param array{
     *     logging?: array{
     *         default_channel?: string,
     *         level?: string,
     *         driver?: string,
     *         path?: string|null,
     *         stream?: string|null,
     *         channels?: array<string, array{
     *             driver?: string,
     *             path?: string|null,
     *             stream?: string|null,
     *         }>,
     *         compliance?: array{
     *             enabled?: bool|int|string,
     *             frameworks?: list<string>,
     *             path?: string,
     *         },
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

        // `logging` and its channels have no DTO of their own -- they are read
        // inline here -- so their unknown keys are collected here too. A channel
        // whose `path` is misspelled otherwise falls back to the driver default and
        // writes somewhere the operator never named.
        $nestedUnknown = UnknownKeys::nestedKeys(
            'logging',
            UnknownKeys::collect($logging, self::KNOWN_LOGGING_KEYS),
        );

        $channelConfigs = [];
        foreach ($logging['channels'] ?? [] as $name => $channelData) {
            if (is_array($channelData)) {
                foreach (
                    UnknownKeys::nestedKeys(
                        'logging.channels.' . $name,
                        UnknownKeys::collect($channelData, self::KNOWN_CHANNEL_KEYS),
                    ) as $unknownChannelKey
                ) {
                    $nestedUnknown[] = $unknownChannelKey;
                }
            }

            $channelConfigs[] = new LoggingChannelConfig(
                name: $name,
                driver: $channelData['driver'] ?? 'file',
                path: $channelData['path'] ?? null,
                stream: $channelData['stream'] ?? null,
            );
        }

        // No explicit `channels` map: synthesise a single default channel from
        // the flat `driver`/`path`/`stream` shape (or a file default). Without
        // this, an empty channels list leaves the logger with NO sinks, so every
        // entry — including the unhandled-exception records the error handler
        // emits before rendering the 500 page — is silently dropped and the log
        // file is never created.
        if ($channelConfigs === []) {
            $flatDriver = $logging['driver'] ?? null;
            $flatPath = $logging['path'] ?? null;
            $flatStream = $logging['stream'] ?? null;
            $channelConfigs[] = new LoggingChannelConfig(
                name: $defaultChannel,
                driver: is_string($flatDriver) ? $flatDriver : 'file',
                path: is_string($flatPath) ? $flatPath : null,
                stream: is_string($flatStream) ? $flatStream : null,
            );
        }

        /** @var array<string, mixed> $auditData */
        $auditData = $data['audit'] ?? [];

        $metrics = MetricsConfig::fromArray($data['metrics'] ?? []);
        $tracing = TracingConfig::fromArray($data['tracing'] ?? []);
        $errorTracking = ErrorTrackingConfig::fromArray($data['error_tracking'] ?? []);
        $audit = AuditConfig::fromArray($auditData, $environment);
        $complianceLogging = ComplianceLoggingConfig::fromArray($logging['compliance'] ?? []);

        return new self(
            defaultLoggingChannel: $defaultChannel,
            loggingLevel: $level,
            loggingChannels: $channelConfigs,
            metrics: $metrics,
            tracing: $tracing,
            errorTracking: $errorTracking,
            audit: $audit,
            complianceLogging: $complianceLogging,
            unknownKeys: [
                ...UnknownKeys::collect($data, self::KNOWN_KEYS),
                ...$nestedUnknown,
                ...UnknownKeys::nested('metrics', $metrics),
                ...UnknownKeys::nested('tracing', $tracing),
                ...UnknownKeys::nested('error_tracking', $errorTracking),
                ...UnknownKeys::nested('audit', $audit),
                // Compliance logging is read from `logging.compliance`, not a
                // top-level section, so its path has to say so.
                ...UnknownKeys::nested('logging.compliance', $complianceLogging),
            ],
        );
    }
}
