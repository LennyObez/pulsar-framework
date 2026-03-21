<?php

declare(strict_types=1);

namespace Pulsar\Http\Exception;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when `Response::redirect()` is asked to issue a `Location` header
 * pointing somewhere that would let an attacker take control of the
 * destination — open-redirect, scheme-injection, or control-character
 * smuggling.
 *
 * Open-redirect bugs are typically classed as low severity in isolation, but
 * they chain into phishing (the attacker uses a trusted domain to redirect
 * the victim to a lookalike), token theft (OAuth `redirect_uri` reflection),
 * and CRLF response splitting when the URL contains `\r` / `\n`. Refusing
 * the redirect at construction time fails loudly during testing and prevents
 * the dangerous response from ever being emitted.
 * @api
 */
#[Api(since: '1.0.0')]
final class UnsafeRedirectException extends InvalidArgumentException
{
    public static function disallowedScheme(string $scheme, string $allowedSchemes): self
    {
        return new self(sprintf(
            'Refusing to redirect to a "%s:" URL. Only %s are accepted by Response::redirect(); '
            . 'pass an absolute URL with one of those schemes or a relative path beginning with "/".',
            $scheme,
            $allowedSchemes,
        ));
    }

    public static function controlCharacters(): self
    {
        return new self(
            'Refusing to redirect to a URL that contains control characters (NUL, CR, LF or other ASCII < 0x20). '
            . 'These bytes can be used to forge additional response headers via CRLF injection.',
        );
    }

    public static function emptyUrl(): self
    {
        return new self(
            'Refusing to redirect to an empty URL — the Location header would be malformed.',
        );
    }

    public static function protocolRelative(): self
    {
        return new self(
            'Refusing to redirect to a protocol-relative URL ("//example.com/..."). '
            . 'Use an absolute URL with an explicit scheme or a path-relative URL beginning with "/".',
        );
    }

    public static function notInAllowlist(string $url): self
    {
        return new self(sprintf(
            'Refusing to redirect to "%s": absolute URL is not in the allowed-hosts list configured for this redirect.',
            $url,
        ));
    }
}
