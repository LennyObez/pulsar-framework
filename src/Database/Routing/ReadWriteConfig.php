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
 * @api
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
     * @param array{
     *     read_hosts?: list<string>,
     *     write_host?: string,
     *     sticky_duration?: string|int,
     *     enabled?: bool|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            readHosts: $data['read_hosts'] ?? [],
            writeHost: $data['write_host'] ?? '127.0.0.1',
            stickyDuration: $data['sticky_duration'] ?? 'request',
            enabled: (bool) ($data['enabled'] ?? false),
        );
    }
}
