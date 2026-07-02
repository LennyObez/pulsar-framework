<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Pulsar\Api\Internal;

use function strpos;
use function substr;

/**
 * Normalizes a request authority (Host header value) for host-based routing.
 *
 * A real `Host` header carries the port on any non-default port
 * (`api.example.com:8000`, `[::1]:8000`), but route host constraints are
 * declared without a port (`api.example.com`, `{tenant}.example.com`). Matching
 * the raw authority against a port-less pattern therefore never succeeds, so the
 * port must be stripped before matching. IPv6 literals are bracketed in the
 * `Host` header (`[::1]`), and the brackets are preserved so a route declared
 * against `[::1]` keeps matching.
 *
 * Shared by {@see Router} and {@see CompiledRouteTree} so both matchers
 * normalize identically.
 */
#[Internal(reason: 'Host-authority normalization is a routing implementation detail')]
final class HostNormalizer
{
    /**
     * Strip the port (and only the port) from a Host authority.
     *
     * - `api.example.com:8000` → `api.example.com`
     * - `api.example.com`      → `api.example.com`
     * - `[::1]:8000`           → `[::1]`
     * - `[::1]`                → `[::1]`
     * - `::1`                  → `::1` (bare IPv6 without brackets cannot carry a
     *                            port per RFC 3986, so it is returned unchanged)
     */
    public static function stripPort(string $authority): string
    {
        if ($authority === '') {
            return '';
        }

        // IPv6 literal: the address is bracketed, an optional `:port` follows `]`.
        if ($authority[0] === '[') {
            $closing = strpos($authority, ']');

            return $closing === false ? $authority : substr($authority, 0, $closing + 1);
        }

        $colon = strpos($authority, ':');

        if ($colon === false) {
            return $authority;
        }

        // More than one colon and no brackets: a bare IPv6 address, which has no
        // port to strip. Leave it untouched rather than truncating the address.
        if (strpos($authority, ':', $colon + 1) !== false) {
            return $authority;
        }

        return substr($authority, 0, $colon);
    }
}
