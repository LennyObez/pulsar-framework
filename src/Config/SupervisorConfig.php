<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/supervisor.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SupervisorConfig implements ReportsUnknownKeys
{
    /** Keys recognised in config/supervisor.php. */
    private const array KNOWN_KEYS = ['enabled', 'recycle', 'stuck_job'];

    public function __construct(
        public bool $enabled = false,
        public int $recycleMaxRequests = 10000,
        public int $recycleMemoryThresholdMb = 256,
        public int $recycleTimeLimitSeconds = 7200,
        public int $stuckJobTimeoutSeconds = 300,
        public int $stuckJobCheckIntervalSeconds = 60,
        public bool $stuckJobMoveToDeadLetter = true,
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
     *     recycle?: array{
     *         max_requests?: int,
     *         memory_threshold_mb?: int,
     *         time_limit_seconds?: int,
     *     },
     *     stuck_job?: array{
     *         timeout_seconds?: int,
     *         check_interval_seconds?: int,
     *         move_to_dead_letter?: bool|int|string,
     *     },
     * } $data Raw array from config/supervisor.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('SUPERVISOR_ENABLED') !== null
            ? $environment->get('SUPERVISOR_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        $recycleData = $data['recycle'] ?? [];
        $stuckData = $data['stuck_job'] ?? [];

        return new self(
            enabled: $enabled,
            recycleMaxRequests: $recycleData['max_requests'] ?? 10000,
            recycleMemoryThresholdMb: $recycleData['memory_threshold_mb'] ?? 256,
            recycleTimeLimitSeconds: $recycleData['time_limit_seconds'] ?? 7200,
            stuckJobTimeoutSeconds: $stuckData['timeout_seconds'] ?? 300,
            stuckJobCheckIntervalSeconds: $stuckData['check_interval_seconds'] ?? 60,
            stuckJobMoveToDeadLetter: (bool) ($stuckData['move_to_dead_letter'] ?? true),
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
