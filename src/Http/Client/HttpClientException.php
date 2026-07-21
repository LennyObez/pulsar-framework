<?php

declare(strict_types=1);

namespace Pulsar\Http\Client;

use JsonException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Http\ResponseStatus;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * Exception thrown by the HTTP client.
 * @api
 */
#[Api(since: '1.0.0')]
final class HttpClientException extends RuntimeException
{
    private function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The server returned an error status.
     */
    #[NoDiscard]
    public static function requestFailed(ResponseStatus $status, string $body): self
    {
        $truncated = mb_strlen($body) > 200 ? mb_substr($body, 0, 200) . '...' : $body;

        return new self(
            sprintf('HTTP request failed with status %d (%s): %s', $status->value, $status->reasonPhrase(), $truncated),
            $status->value,
        );
    }

    /**
     * The connection to the remote host failed.
     */
    #[NoDiscard]
    public static function connectionFailed(string $url, string $reason): self
    {
        return new self(
            sprintf('Connection to "%s" failed: %s', $url, $reason),
        );
    }

    /**
     * The request timed out.
     */
    #[NoDiscard]
    public static function timeout(string $url, float $seconds): self
    {
        return new self(
            sprintf('Request to "%s" timed out after %.1f seconds', $url, $seconds),
        );
    }

    /**
     * SSRF protection blocked the request.
     */
    #[NoDiscard]
    public static function ssrfBlocked(string $host, string $ip): self
    {
        return new self(
            sprintf('SSRF protection blocked request to "%s" (resolved to private IP %s)', $host, $ip),
        );
    }

    /**
     * SSRF protection refused a host it could not resolve to an IP.
     *
     * Fail-closed: an unresolvable host cannot be validated against the
     * private/reserved ranges, and a DNS-rebinding attacker could return a
     * private address at connect time. Refusing is safer than connecting to an
     * unvalidated, re-resolvable name.
     */
    #[NoDiscard]
    public static function unresolvableHost(string $host): self
    {
        return new self(
            sprintf('SSRF protection blocked request to "%s" (host could not be resolved to a verifiable IP)', $host),
        );
    }

    /**
     * SSRF protection blocked a non-HTTP(S) URL scheme.
     *
     * Guards against a redirect (or caller) steering the client onto file://,
     * gopher://, dict:// and similar schemes that would read local resources or
     * reach unintended services.
     */
    #[NoDiscard]
    public static function disallowedScheme(string $url, string $scheme): self
    {
        return new self(
            sprintf('SSRF protection blocked request to "%s" (disallowed scheme "%s"; only http/https allowed)', $url, $scheme),
        );
    }

    /**
     * The response body could not be parsed as JSON.
     */
    #[NoDiscard]
    public static function invalidJson(JsonException $previous): self
    {
        return new self(
            sprintf('Failed to parse response body as JSON: %s', $previous->getMessage()),
            previous: $previous,
        );
    }

    /**
     * The response exceeded the configured maximum size.
     */
    #[NoDiscard]
    public static function responseTooLarge(int $maxBytes): self
    {
        return new self(
            sprintf('Response body exceeded maximum size of %d bytes', $maxBytes),
        );
    }

    /**
     * Too many redirects were followed.
     */
    #[NoDiscard]
    public static function tooManyRedirects(int $max): self
    {
        return new self(
            sprintf('Maximum number of redirects (%d) exceeded', $max),
        );
    }

    /**
     * SSL/TLS verification failed.
     */
    #[NoDiscard]
    public static function sslError(string $url, string $reason): self
    {
        return new self(
            sprintf('SSL verification failed for "%s": %s', $url, $reason),
        );
    }
}
