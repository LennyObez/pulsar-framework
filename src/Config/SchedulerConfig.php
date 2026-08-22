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
final readonly class SchedulerConfig implements ReportsUnknownKeys
{
    /** Keys recognised in config/scheduler.php. */
    private const array KNOWN_KEYS = ['enabled', 'timezone', 'max_execution_time', 'lock_timeout', 'log_output'];

    public function __construct(
        public bool $enabled = false,
        public string $timezone = 'UTC',
        public int $maxExecutionTime = 3600,
        public int $lockTimeout = 300,
        public bool $logOutput = true,
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
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
