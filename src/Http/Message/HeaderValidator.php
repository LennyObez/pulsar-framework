<?php

declare(strict_types=1);

namespace Pulsar\Http\Message;

use InvalidArgumentException;
use Pulsar\Api\Internal;

use function is_string;
use function ord;
use function preg_match;
use function sprintf;
use function strlen;
use function strpbrk;

/**
 * RFC 7230 / 9110 header field name and value validator.
 *
 * SEC-IN-01: PSR-7 implementations that accept arbitrary header names and
 * values without validation expose every emit-path to CRLF injection. The
 * canonical attack splits a header value with `\r\n` and either adds a new
 * header or starts an early response body. Browsers and proxies have
 * historically diverged on how malformed names interact with caching,
 * authentication, and CORS, so the validator is fail-closed: any name that
 * is not a strict RFC 7230 §3.2.6 token, or any value containing CR/LF/NUL,
 * is rejected with InvalidArgumentException (the PSR-7 contract for
 * malformed headers).
 *
 * Token grammar (RFC 7230 §3.2.6):
 *
 *     token   = 1*tchar
 *     tchar   = "!" / "#" / "$" / "%" / "&" / "'" / "*" / "+" / "-" /
 *               "." / "^" / "_" / "`" / "|" / "~" / DIGIT / ALPHA
 *
 * Field-value grammar (RFC 7230 §3.2): VCHAR / obs-text / SP / HTAB — but
 * obs-fold (CRLF SP) is deprecated by RFC 7230 §3.2.4 and forbidden in
 * generated messages. The validator therefore refuses any CR, LF, or NUL.
 */
#[Internal(reason: 'Header validation primitive; surface kept narrow')]
final readonly class HeaderValidator
{
    private const string TOKEN_PATTERN = "/^[!#\$%&'*+\\-.^_`|~0-9A-Za-z]+$/";

    /**
     * Validate a header name against RFC 7230 §3.2.6 (token).
     *
     * @throws InvalidArgumentException when the name is empty, non-string, or
     *                                  contains a character outside the token
     *                                  alphabet (notably CR/LF/space/`:`)
     */
    public static function assertValidName(string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Header name must not be empty');
        }

        if (preg_match(self::TOKEN_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Header name "%s" is not a valid RFC 7230 token', self::previewForError($name)),
            );
        }
    }

    /**
     * Validate a header value against RFC 7230 §3.2.
     *
     * Accepts string or list<string>. Each value is checked for raw CR, LF,
     * and NUL characters; any presence rejects the entire value because
     * CRLF in a header value is the canonical response-splitting vector.
     *
     * @param string|list<string> $value
     * @throws InvalidArgumentException when the value contains CR/LF/NUL or
     *                                  is not a string/list of strings
     */
    public static function assertValidValue(string|array $value): void
    {
        if (is_string($value)) {
            self::assertNoCrlfNul($value);

            return;
        }

        foreach ($value as $item) {
            self::assertNoCrlfNul($item);
        }
    }

    private static function assertNoCrlfNul(string $value): void
    {
        if (strpbrk($value, "\r\n\0") !== false) {
            throw new InvalidArgumentException(
                sprintf(
                    'Header value contains an illegal CR, LF, or NUL character: "%s"',
                    self::previewForError($value),
                ),
            );
        }
    }

    private static function previewForError(string $raw): string
    {
        // Cap and ASCII-escape for safer error logs.
        $preview = '';
        $count = 0;

        for ($i = 0, $len = strlen($raw); $i < $len && $count < 64; $i++) {
            $byte = $raw[$i];
            $ord = ord($byte);

            if ($ord >= 0x20 && $ord < 0x7F) {
                $preview .= $byte;
            } else {
                $preview .= sprintf('\\x%02X', $ord);
            }

            $count++;
        }

        if (strlen($raw) > 64) {
            $preview .= '…';
        }

        return $preview;
    }
}
