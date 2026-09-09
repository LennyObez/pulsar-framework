<?php

declare(strict_types=1);

namespace Pulsar\Database\Routing;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\ReportsUnknownKeys;
use Pulsar\Config\UnknownKeys;

/**
 * Configuration for read/write connection routing.
 *
 * When enabled, SELECT queries are routed to read replicas while write
 * operations go to the primary host. Sticky duration controls how long
 * the connection remains pinned to the primary after a write.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ReadWriteConfig implements ReportsUnknownKeys
{
    /** Keys read from the `read_write` sub-array of config/database.php. */
    private const array KNOWN_KEYS = ['read_hosts', 'write_host', 'sticky_duration', 'enabled'];

    /**
     * @param list<string> $readHosts Hosts, or the names of entries under `connections`.
     *     {@see ReadWriteConnections} resolves them before the manager is built: a name
     *     that matches a configured connection is used verbatim, anything else is a host
     *     and gets a connection derived from the primary with the host replaced.
     * @param string $writeHost The host writes go to, or `''` for the default connection
     *     exactly as configured. Resolved the same way as a read host.
     * @param string|int $stickyDuration 'request' for request-scoped, or milliseconds
     * @param list<string> $unknownKeys Keys present in the raw `read_write` array that
     *     this DTO does not read — a misspelled `read_hosts` sends every read to the
     *     writer, which looks like a performance mystery rather than a config typo.
     */
    public function __construct(
        public array $readHosts = [],
        public string $writeHost = '',
        public string|int $stickyDuration = 'request',
        public bool $enabled = false,
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
            // Empty, not '127.0.0.1'. `write_host` now selects the connection writes go
            // to, and a default of localhost would repoint the primary of every
            // deployment that simply left the key out. Absent means "the default
            // connection exactly as `connections` configures it".
            writeHost: $data['write_host'] ?? '',
            stickyDuration: $data['sticky_duration'] ?? 'request',
            enabled: (bool) ($data['enabled'] ?? false),
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
