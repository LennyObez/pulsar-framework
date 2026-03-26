<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/scheduler.php`.
 * @api
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
     * @param array{
     *     enabled?: bool|int|string,
     *     timezone?: string,
     *     max_execution_time?: int,
     *     lock_timeout?: int,
     *     log_output?: bool|int|string,
     * } $data Raw array from config/scheduler.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('SCHEDULER_ENABLED') !== null
            ? $environment->get('SCHEDULER_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        return new self(
            enabled: $enabled,
            timezone: $data['timezone'] ?? 'UTC',
            maxExecutionTime: $data['max_execution_time'] ?? 3600,
            lockTimeout: $data['lock_timeout'] ?? 300,
            logOutput: (bool) ($data['log_output'] ?? true),
        );
    }
}
