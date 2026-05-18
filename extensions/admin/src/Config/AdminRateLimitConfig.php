<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Rate limit configuration for the admin panel.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AdminRateLimitConfig
{
    public function __construct(
        public int $readLimit,
        public int $writeLimit,
        public int $exportLimit,
        public int $windowSeconds,
    ) {}

    /**
     * @param array{
     *     read_limit?: int,
     *     write_limit?: int,
     *     export_limit?: int,
     *     window_seconds?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            readLimit: $data['read_limit'] ?? 120,
            writeLimit: $data['write_limit'] ?? 30,
            exportLimit: $data['export_limit'] ?? 5,
            windowSeconds: $data['window_seconds'] ?? 60,
        );
    }
}
