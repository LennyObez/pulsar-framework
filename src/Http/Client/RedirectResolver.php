<?php

declare(strict_types=1);

namespace Pulsar\Http\Client;

use Pulsar\Api\Internal;

use function is_array;
use function parse_url;
use function str_starts_with;
use function strrpos;
use function substr;
use function trim;

/**
 * Pure redirect-following helpers for {@see HttpClient}.
 *
 * Split out so the target-resolution logic — the part with real edge cases
 * (absolute, protocol-relative, absolute-path, relative Location values) — is
 * unit-testable in isolation from the network. Every URL this produces is still
 * re-validated by HttpClient's per-hop SSRF guard before a connection is made.
 */
#[Internal]
final class RedirectResolver
{
    /**
     * Whether a status code is a redirect the client should follow.
     */
    public static function isRedirect(int $status): bool
    {
        return $status === 301
            || $status === 302
            || $status === 303
            || $status === 307
            || $status === 308;
    }

    /**
     * Resolve a redirect Location against the current request URL.
     *
     * Handles absolute URLs, protocol-relative (`//host/path`), absolute-path
     * (`/path`), and relative (`sub/path`) forms. Scheme legality is not decided
     * here — the caller's SSRF guard rejects anything but http/https on the next
     * hop.
     */
    public static function resolve(string $base, string $location): string
    {
        $location = trim($location);
        $parsedLocation = parse_url($location);

        // Absolute URL: scheme + host present.
        if (is_array($parsedLocation) && isset($parsedLocation['scheme'], $parsedLocation['host'])) {
            return $location;
        }

        $parsedBase = parse_url($base);
        $scheme = is_array($parsedBase) && isset($parsedBase['scheme']) ? (string) $parsedBase['scheme'] : 'https';
        $host = is_array($parsedBase) && isset($parsedBase['host']) ? (string) $parsedBase['host'] : '';
        $port = is_array($parsedBase) && isset($parsedBase['port']) ? ':' . $parsedBase['port'] : '';
        $authority = $scheme . '://' . $host . $port;

        // Protocol-relative: //host/path
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        // Absolute path: /path
        if (str_starts_with($location, '/')) {
            return $authority . $location;
        }

        // Relative path: resolve against the base path's directory.
        $basePath = is_array($parsedBase) && isset($parsedBase['path']) ? (string) $parsedBase['path'] : '/';
        $slash = strrpos($basePath, '/');
        $dir = $slash === false ? '/' : substr($basePath, 0, $slash + 1);

        return $authority . $dir . $location;
    }
}
