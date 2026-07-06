<?php

declare(strict_types=1);

namespace Pulsar\Observability\Profiler;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Configuration for the per-request performance profiler.
 *
 * Opt-in (disabled by default): profiling adds per-request overhead and exposes
 * timing detail, so it is meant for development/staging. When enabled, the
 * {@see ProfilerMiddleware} times each request and a Server-Timing header
 * surfaces totals; DB queries and cache hits/misses are recorded into the same
 * profiler when the database and cache are wired.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ProfilerConfig
{
    public function __construct(
        public bool $enabled = false,
        public int $maxEntries = 512,
        public int $maxProfiles = 50,
    ) {}

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     max_entries?: int|string,
     *     max_profiles?: int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null),
            maxEntries: Coerce::int($data['max_entries'] ?? null, 512),
            maxProfiles: Coerce::int($data['max_profiles'] ?? null, 50),
        );
    }
}
