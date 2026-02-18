<?php

declare(strict_types=1);

namespace Pulsar\Database\Routing;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for read/write connection routing.
 *
 * When enabled, SELECT queries are routed to read replicas while write
 * operations go to the primary host. Sticky duration controls how long
 * the connection remains pinned to the primary after a write.
 */
#[Api(since: '1.0.0')]
final readonly class ReadWriteConfig
{
    /**
     * @param list<string> $readHosts
     * @param string|int $stickyDuration 'request' for request-scoped, or milliseconds
     */
    public function __construct(
        public array $readHosts = [],
        public string $writeHost = '',
        public string|int $stickyDuration = 'request',
        public bool $enabled = false,
    ) {}

    /**
     * Build from a raw config array.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<string> $readHosts */
        $readHosts = $data['read_hosts'] ?? [];

        /** @var string $writeHost */
        $writeHost = $data['write_host'] ?? '127.0.0.1';

        /** @var string|int $rawSticky */
        $rawSticky = $data['sticky_duration'] ?? 'request';
        $stickyDuration = $rawSticky;

        return new self(
            readHosts: $readHosts,
            writeHost: $writeHost,
            stickyDuration: $stickyDuration,
            enabled: (bool) ($data['enabled'] ?? false),
        );
    }
}
