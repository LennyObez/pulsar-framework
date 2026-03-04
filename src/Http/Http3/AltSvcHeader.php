<?php

declare(strict_types=1);

namespace Pulsar\Http\Http3;

use NoDiscard;
use Pulsar\Api\Api;

use function implode;

/**
 * Alt-Svc header generation for HTTP/3 advertisement.
 *
 * The Alt-Svc header tells browsers that the server supports HTTP/3 (QUIC)
 * on a specified port, enabling protocol upgrade for subsequent requests.
 *
 * Typically configured at the reverse proxy (nginx) level, but this helper
 * enables application-level control for dynamic port configuration or
 * multi-origin setups.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Alt-Svc
 * @see https://datatracker.ietf.org/doc/html/rfc7838
 */
#[Api(since: '1.0.0')]
final class AltSvcHeader
{
    /** @var list<AltSvcEntry> */
    private array $entries = [];

    /**
     * Advertise HTTP/3 over QUIC on the given port.
     *
     * @param int $port UDP port for QUIC (typically 443)
     * @param int $maxAge Max-age in seconds (how long the client should remember this)
     * @param bool $persist Whether to persist across network changes
     */
    public function h3(int $port = 443, int $maxAge = 86400, bool $persist = false): self
    {
        $this->entries[] = new AltSvcEntry('h3', $port, $maxAge, $persist);

        return $this;
    }

    /**
     * Advertise HTTP/2 over TLS.
     *
     * @param int $port TCP port (typically 443)
     * @param int $maxAge Max-age in seconds
     */
    public function h2(int $port = 443, int $maxAge = 86400): self
    {
        $this->entries[] = new AltSvcEntry('h2', $port, $maxAge, false);

        return $this;
    }

    /**
     * Add a custom alternative service entry.
     */
    public function addEntry(string $protocol, int $port, int $maxAge = 86400, bool $persist = false): self
    {
        $this->entries[] = new AltSvcEntry($protocol, $port, $maxAge, $persist);

        return $this;
    }

    /**
     * Clear all alternative services. Use to signal that the client should
     * forget any cached Alt-Svc information.
     */
    public function clear(): self
    {
        $this->entries = [];

        return $this;
    }

    /**
     * Generate the Alt-Svc header value.
     *
     * Returns "clear" if no entries are registered (signals clients to
     * forget cached alternative services).
     *
     * Examples:
     *   h3=":443"; ma=86400
     *   h3=":443"; ma=86400; persist=1, h2=":443"; ma=3600
     */
    #[NoDiscard]
    public function toHeaderValue(): string
    {
        if ($this->entries === []) {
            return 'clear';
        }

        return implode(', ', array_map(
            static fn(AltSvcEntry $e): string => $e->toHeaderValue(),
            $this->entries,
        ));
    }

    /**
     * Get all registered entries.
     *
     * @return list<AltSvcEntry>
     */
    #[NoDiscard]
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Whether any entries have been registered.
     */
    public function isEmpty(): bool
    {
        return $this->entries === [];
    }
}
