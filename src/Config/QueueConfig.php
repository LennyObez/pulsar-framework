<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/queue.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class QueueConfig implements ReportsUnknownKeys
{
    /** Keys recognised in config/queue.php. */
    private const array KNOWN_KEYS = [
        'enabled', 'default_queue', 'driver', 'driver_options', 'worker', 'retry',
        'dead_letter', 'middleware', 'rate_limit',
    ];

    /**
     * @param array<string, mixed> $driverOptions Driver-specific configuration (host, port, credentials, etc.)
     */
    public function __construct(
        public bool $enabled = false,
        public QueueDriverType $driver = QueueDriverType::Sync,
        public string $defaultQueue = 'default',
        public int $workerMaxJobs = 1000,
        public int $workerMaxMemoryMb = 256,
        public int $workerTimeLimitSeconds = 3600,
        public int $workerSleepMs = 1000,
        public int $retryMaxAttempts = 3,
        public int $retryBaseDelayMs = 1000,
        public int $retryMaxDelayMs = 60000,
        public float $retryMultiplier = 2.0,
        public bool $deadLetterEnabled = true,
        public int $deadLetterRetentionDays = 30,
        public array $driverOptions = [],
        public bool $deadLetterRegulated = false,
        public bool $encryptPayloads = false,
        public bool $enforceEffectClassification = false,
        public bool $preventDuplicates = false,
        public int $preventDuplicatesTtlSeconds = 300,
        public bool $rateLimitEnabled = false,
        public int $rateLimitTtlSeconds = 1,
        public int $rateLimitTimeoutMs = 0,
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
     *     driver?: string,
     *     default_queue?: string,
     *     worker?: array{
     *         max_jobs?: int,
     *         max_memory_mb?: int,
     *         time_limit_seconds?: int,
     *         sleep_ms?: int,
     *     },
     *     retry?: array{
     *         max_attempts?: int,
     *         base_delay_ms?: int,
     *         max_delay_ms?: int,
     *         multiplier?: float|int,
     *     },
     *     dead_letter?: array{
     *         enabled?: bool|int|string,
     *         retention_days?: int,
     *         regulated?: bool|int|string,
     *     },
     *     driver_options?: array<string, mixed>,
     *     middleware?: array{
     *         encrypt_payloads?: bool|int|string,
     *         enforce_effect_classification?: bool|int|string,
     *         prevent_duplicates?: array{enabled?: bool|int|string, ttl_seconds?: int},
     *         rate_limit?: array{enabled?: bool|int|string, ttl_seconds?: int, timeout_ms?: int},
     *     },
     * } $data Raw array from config/queue.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('QUEUE_ENABLED') !== null
            ? $environment->get('QUEUE_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        $driverValue = $environment->get('QUEUE_DRIVER') ?? $data['driver'] ?? 'sync';
        $driver = QueueDriverType::from($driverValue);

        $workerData = $data['worker'] ?? [];
        $retryData = $data['retry'] ?? [];
        $dlData = $data['dead_letter'] ?? [];
        $mwData = $data['middleware'] ?? [];
        $dedupData = $mwData['prevent_duplicates'] ?? [];
        $rateData = $mwData['rate_limit'] ?? [];

        return new self(
            enabled: $enabled,
            driver: $driver,
            defaultQueue: $data['default_queue'] ?? 'default',
            workerMaxJobs: $workerData['max_jobs'] ?? 1000,
            workerMaxMemoryMb: $workerData['max_memory_mb'] ?? 256,
            workerTimeLimitSeconds: $workerData['time_limit_seconds'] ?? 3600,
            workerSleepMs: $workerData['sleep_ms'] ?? 1000,
            retryMaxAttempts: $retryData['max_attempts'] ?? 3,
            retryBaseDelayMs: $retryData['base_delay_ms'] ?? 1000,
            retryMaxDelayMs: $retryData['max_delay_ms'] ?? 60000,
            retryMultiplier: (float) ($retryData['multiplier'] ?? 2.0),
            deadLetterEnabled: (bool) ($dlData['enabled'] ?? true),
            deadLetterRetentionDays: $dlData['retention_days'] ?? 30,
            driverOptions: $data['driver_options'] ?? [],
            deadLetterRegulated: (bool) ($dlData['regulated'] ?? false),
            encryptPayloads: (bool) ($mwData['encrypt_payloads'] ?? false),
            enforceEffectClassification: (bool) ($mwData['enforce_effect_classification'] ?? false),
            preventDuplicates: (bool) ($dedupData['enabled'] ?? false),
            preventDuplicatesTtlSeconds: $dedupData['ttl_seconds'] ?? 300,
            rateLimitEnabled: (bool) ($rateData['enabled'] ?? false),
            rateLimitTtlSeconds: $rateData['ttl_seconds'] ?? 1,
            rateLimitTimeoutMs: $rateData['timeout_ms'] ?? 0,
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
