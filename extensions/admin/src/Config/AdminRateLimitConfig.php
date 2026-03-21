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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var int $readLimit */
        $readLimit = $data['read_limit'] ?? 120;
        /** @var int $writeLimit */
        $writeLimit = $data['write_limit'] ?? 30;
        /** @var int $exportLimit */
        $exportLimit = $data['export_limit'] ?? 5;
        /** @var int $windowSeconds */
        $windowSeconds = $data['window_seconds'] ?? 60;

        return new self(
            readLimit: $readLimit,
            writeLimit: $writeLimit,
            exportLimit: $exportLimit,
            windowSeconds: $windowSeconds,
        );
    }
}
