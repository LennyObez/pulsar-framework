<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/scheduler.php`.
 *
 * There is no `lock_timeout` here, and the omission is deliberate. The file
 * carried one — parsed into a `$lockTimeout` property, documented as "seconds to
 * hold a job lock to prevent overlapping execution" — that no code read. Overlap
 * prevention takes its lock lifetime from
 * {@see \Pulsar\Scheduler\ScheduleBuilder::withoutOverlapping()}, per job,
 * because the lifetime that is correct for a job is its own worst-case runtime
 * and no single global number is right for two jobs at once. A key that governs
 * nothing is worse than a missing one: an operator who raises it believes they
 * have changed something. It is gone from {@see KNOWN_KEYS} as well, so a
 * `lock_timeout` still sitting in a deployment's `config/scheduler.php` is now
 * reported as an unknown key (ADR-0036) instead of being quietly accepted.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SchedulerConfig implements ReportsUnknownKeys
{
    /** Keys recognised in config/scheduler.php. */
    private const array KNOWN_KEYS = ['enabled', 'timezone', 'max_execution_time', 'log_output'];

    public function __construct(
        public bool $enabled = false,
        public string $timezone = 'UTC',
        public int $maxExecutionTime = 3600,
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
            logOutput: (bool) ($data['log_output'] ?? true),
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
