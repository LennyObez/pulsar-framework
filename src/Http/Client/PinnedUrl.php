<?php

declare(strict_types=1);

namespace Pulsar\Http\Client;

use Pulsar\Api\Internal;

use function filter_var;
use function is_array;
use function parse_url;

use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;

/**
 * Rebuilds a URL with its authority host pinned to a validated IP address.
 *
 * Split out from {@see HttpClient} so the pinning is unit-testable and robust.
 * The URL is reconstructed from its parsed components so ONLY the authority host
 * is replaced. A naive string replace of the host substring rewrites the FIRST
 * occurrence — for a URL whose userinfo repeats the host (http://h@h/) that is
 * the userinfo, leaving the real connect host unpinned. The client would then
 * re-resolve it at connect time, reopening the DNS-rebinding TOCTOU the pin
 * exists to prevent. Userinfo, port, path, query and fragment are preserved.
 */
#[Internal]
final class PinnedUrl
{
    /**
     * Return $url with its authority host replaced by $resolvedIp.
     *
     * IPv6 addresses are bracketed as URL syntax requires. When the URL has no
     * host (nothing to pin) it is returned unchanged.
     */
    public static function withHost(string $url, string $resolvedIp): string
    {
        $parsed = parse_url($url);

        if (!is_array($parsed) || !isset($parsed['host'])) {
            return $url;
        }

        $host = filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '[' . $resolvedIp . ']'
            : $resolvedIp;

        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';

        $userinfo = '';
        if (isset($parsed['user'])) {
            $userinfo = $parsed['user'];
            if (isset($parsed['pass'])) {
                $userinfo .= ':' . $parsed['pass'];
            }
            $userinfo .= '@';
        }

        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        return $scheme . $userinfo . $host . $port . $path . $query . $fragment;
    }
}
