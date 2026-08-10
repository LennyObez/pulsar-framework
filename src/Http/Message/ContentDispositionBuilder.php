<?php

declare(strict_types=1);

namespace Pulsar\Http\Message;

use InvalidArgumentException;
use Pulsar\Api\Internal;

use function is_string;
use function ord;
use function preg_replace;
use function rawurlencode;
use function strlen;

/**
 * RFC 5987 / 6266 Content-Disposition header value builder.
 *
 * Building `Content-Disposition` with `sprintf('attachment; filename="%s"',
 * $name)` and a basename() of an arbitrary file path lets an attacker who controls
 * the filename inject CRLF (response splitting), embed double-quotes (break out of
 * the quoted-string and add their own parameters), or hide Unicode in a header that
 * browsers parse divergently. The builder emits the RFC 6266 / 5987 canonical form:
 *
 *     Content-Disposition: <type>; filename="<ascii-fallback>"; filename*=UTF-8''<pct>
 *
 * - ASCII fallback: non-ASCII collapsed to `_`, `"` and `\` escaped, control bytes
 *   (CR/LF/NUL/<0x20 except SP/HTAB) stripped.
 * - RFC 5987 extended (`filename*`): UTF-8 percent-encoded so it round-trips any
 *   Unicode filename without breaking the header grammar.
 *
 * The builder REFUSES filenames that, after sanitisation, are empty — that signals
 * a hostile or malformed input.
 */
#[Internal]
final readonly class ContentDispositionBuilder
{
    /**
     * Build an `attachment` Content-Disposition value.
     */
    public static function attachment(string $filename): string
    {
        return self::build('attachment', $filename);
    }

    /**
     * Build an `inline` Content-Disposition value.
     */
    public static function inline(string $filename): string
    {
        return self::build('inline', $filename);
    }

    private static function build(string $disposition, string $filename): string
    {
        if ($filename === '') {
            throw new InvalidArgumentException('Content-Disposition filename must not be empty');
        }

        $ascii = self::asciiFallback($filename);
        $extended = rawurlencode($filename);

        if ($ascii === '') {
            throw new InvalidArgumentException(
                'Content-Disposition filename has no representable ASCII characters; reject or rename before download',
            );
        }

        // RFC 6266 §4.1: filename and filename* SHOULD both appear, ASCII first
        // for legacy clients. UTF-8'' is the only currently registered charset
        // form per RFC 5987 §3.2.1.
        return "$disposition; filename=\"$ascii\"; filename*=UTF-8''$extended";
    }

    /**
     * Produce the ASCII-only fallback per RFC 6266 §5.
     *
     * Non-ASCII chars collapse to `_`. `"` and `\` are escaped. Control bytes
     * (anything below 0x20 except 0x20 itself, plus 0x7F) are dropped — they
     * would either break the quoted-string grammar or inject CRLF.
     */
    private static function asciiFallback(string $filename): string
    {
        // Strip control characters (CR, LF, NUL, all <0x20 except SP/HTAB).
        $stripped = preg_replace('/[\x00-\x1F\x7F]/', '', $filename);

        if (!is_string($stripped)) {
            return '';
        }

        $out = '';

        for ($i = 0, $len = strlen($stripped); $i < $len; $i++) {
            $byte = $stripped[$i];
            $ord = ord($byte);

            if ($ord >= 0x80) {
                $out .= '_';

                continue;
            }

            if ($byte === '"' || $byte === '\\') {
                $out .= '\\' . $byte;

                continue;
            }

            $out .= $byte;
        }

        // Collapse runs of underscores from non-ASCII collapse to a single _.
        $collapsed = preg_replace('/_+/', '_', $out);

        return is_string($collapsed) ? $collapsed : $out;
    }
}
