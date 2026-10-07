<?php

declare(strict_types=1);

namespace Pulsar\Http\Exception;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when a header name or value contains bytes that would break the
 * HTTP wire protocol or smuggle additional headers via CRLF injection.
 *
 * RFC 7230 §3.2 forbids CR (`\r`), LF (`\n`) and NUL inside header values.
 * Any of those characters in a header value lets an attacker forge a
 * trailing header, an entire second response, or terminate the headers
 * block early — the canonical CRLF response-splitting attack.
 * @api
 */
#[Api(since: '1.0.0')]
final class UnsafeHeaderException extends InvalidArgumentException
{
    public static function emptyName(): self
    {
        return new self('HTTP header names must be non-empty.');
    }

    public static function invalidNameCharacters(string $name): self
    {
        return new self(sprintf(
            'HTTP header name "%s" contains forbidden characters. RFC 7230 §3.2 only allows ASCII letters, digits and the token characters "!#$%%&\'*+-.^_`|~".',
            self::redact($name),
        ));
    }

    public static function controlCharactersInValue(string $name): self
    {
        return new self(sprintf(
            'HTTP header "%s" value contains forbidden control characters (CR, LF, NUL or other ASCII < 0x20). '
            . 'These bytes can be used to smuggle additional headers via CRLF injection.',
            self::redact($name),
        ));
    }

    private static function redact(string $name): string
    {
        // Replace control characters in error messages so the name itself
        // cannot be used to forge further log injection downstream.
        $sanitised = (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $name);

        return mb_strlen($sanitised) > 64 ? mb_substr($sanitised, 0, 64) . '…' : $sanitised;
    }
}
