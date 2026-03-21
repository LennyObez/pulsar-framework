<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/scheduler.php`.
 */
#[Api(since: '1.0.0')]
final readonly class SchedulerConfig
{
    public function __construct(
        public bool $enabled = false,
        public string $timezone = 'UTC',
        public int $maxExecutionTime = 3600,
        public int $lockTimeout = 300,
        public bool $logOutput = true,
    ) {}

    /**
     * @param array<string, mixed> $data Raw array from config/scheduler.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('SCHEDULER_ENABLED') !== null
            ? $environment->get('SCHEDULER_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        /** @var string $timezone */
        $timezone = $data['timezone'] ?? 'UTC';
        /** @var int $maxExecutionTime */
        $maxExecutionTime = $data['max_execution_time'] ?? 3600;
        /** @var int $lockTimeout */
        $lockTimeout = $data['lock_timeout'] ?? 300;

        return new self(
            enabled: $enabled,
            timezone: $timezone,
            maxExecutionTime: $maxExecutionTime,
            lockTimeout: $lockTimeout,
            logOutput: (bool) ($data['log_output'] ?? true),
        );
    }
}
