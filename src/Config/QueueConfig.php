<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_float;
use function is_int;
use function is_string;

/**
 * Typed configuration DTO for `config/queue.php`.
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
     * @param array<string, mixed> $data Raw array from config/queue.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('QUEUE_ENABLED') !== null
            ? $environment->get('QUEUE_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        $driverValue = $environment->get('QUEUE_DRIVER') ?? ($data['driver'] ?? 'sync');
        $driver = QueueDriverType::from(is_string($driverValue) ? $driverValue : 'sync');

        /** @var string $defaultQueue */
        $defaultQueue = $data['default_queue'] ?? 'default';

        /** @var array<string, mixed> $workerData */
        $workerData = $data['worker'] ?? [];

        /** @var array<string, mixed> $retryData */
        $retryData = $data['retry'] ?? [];

        /** @var array<string, mixed> $dlData */
        $dlData = $data['dead_letter'] ?? [];

        $rawMaxJobs = $workerData['max_jobs'] ?? 1000;
        $rawMaxMemory = $workerData['max_memory_mb'] ?? 256;
        $rawTimeLimit = $workerData['time_limit_seconds'] ?? 3600;
        $rawSleep = $workerData['sleep_ms'] ?? 1000;
        $rawMaxAttempts = $retryData['max_attempts'] ?? 3;
        $rawBaseDelay = $retryData['base_delay_ms'] ?? 1000;
        $rawMaxDelay = $retryData['max_delay_ms'] ?? 60000;
        $rawMultiplier = $retryData['multiplier'] ?? 2.0;
        $rawDlRetention = $dlData['retention_days'] ?? 30;

        /** @var array<string, mixed> $rawDriverOptions */
        $rawDriverOptions = $data['driver_options'] ?? [];

        return new self(
            enabled: $enabled,
            driver: $driver,
            defaultQueue: $defaultQueue,
            workerMaxJobs: is_int($rawMaxJobs) ? $rawMaxJobs : 1000,
            workerMaxMemoryMb: is_int($rawMaxMemory) ? $rawMaxMemory : 256,
            workerTimeLimitSeconds: is_int($rawTimeLimit) ? $rawTimeLimit : 3600,
            workerSleepMs: is_int($rawSleep) ? $rawSleep : 1000,
            retryMaxAttempts: is_int($rawMaxAttempts) ? $rawMaxAttempts : 3,
            retryBaseDelayMs: is_int($rawBaseDelay) ? $rawBaseDelay : 1000,
            retryMaxDelayMs: is_int($rawMaxDelay) ? $rawMaxDelay : 60000,
            retryMultiplier: is_float($rawMultiplier) || is_int($rawMultiplier) ? (float) $rawMultiplier : 2.0,
            deadLetterEnabled: (bool) ($dlData['enabled'] ?? true),
            deadLetterRetentionDays: is_int($rawDlRetention) ? $rawDlRetention : 30,
            driverOptions: $rawDriverOptions,
        );
    }
}
