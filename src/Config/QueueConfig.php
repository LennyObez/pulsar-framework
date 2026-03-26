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
final readonly class QueueConfig
{
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
    ) {}

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
     *     },
     *     driver_options?: array<string, mixed>,
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
        );
    }
}
