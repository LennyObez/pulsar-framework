<?php

declare(strict_types=1);

namespace Pulsar\Http;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function in_array;
use function parse_url;
use function str_starts_with;
use function strtolower;

/**
 * Validates redirect URLs to prevent open redirect attacks.
 *
 * Allows:
 * - Relative paths starting with "/" (but not "//")
 * - Absolute URLs with http/https scheme matching an allowed-hosts list
 *
 * Rejects protocol-relative URLs, data: URIs, javascript: URIs,
 * and any absolute URL whose host is not in the allowed list.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class SafeRedirect
{
    /**
     * Validate a redirect URL.
     *
     * @param string       $url          The redirect target
     * @param list<string> $allowedHosts Allowed hosts for absolute URLs (e.g. ['example.com', 'app.example.com'])
     *
     * @throws InvalidArgumentException If the URL is unsafe for redirection
     */
    public static function validate(string $url, array $allowedHosts = []): string
    {
        if ($url === '') {
            throw new InvalidArgumentException('Redirect URL must not be empty.');
        }

        // Block protocol-relative URLs (//evil.com)
        if (str_starts_with($url, '//')) {
            throw new InvalidArgumentException('Protocol-relative redirect URLs are not allowed.');
        }

        // Allow safe relative paths: must start with /
        if (str_starts_with($url, '/')) {
            return $url;
        }

        // Parse the URL to inspect scheme and host
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Redirect URL must be a relative path starting with "/" or an absolute URL with scheme and host.');
        }

        $scheme = strtolower($parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException("Redirect URL scheme \"$scheme\" is not allowed. Only http and https are permitted.");
        }

        if ($allowedHosts === []) {
            throw new InvalidArgumentException('Absolute redirect URLs require a non-empty allowed-hosts list. Use a relative path or configure allowed hosts.');
        }

        $host = strtolower($parts['host']);

        if (!in_array($host, $allowedHosts, true)) {
            throw new InvalidArgumentException("Redirect to host \"$host\" is not allowed.");
        }

        return $url;
    }
}
