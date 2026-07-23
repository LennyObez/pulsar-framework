<?php

declare(strict_types=1);

namespace Pulsar\Http;

use NoDiscard;
use Pulsar\Api\Api;

use function explode;
use function implode;
use function strtolower;
use function trim;

/**
 * Helpers for building a `Vary` response header without losing existing tokens.
 *
 * A response whose selection depends on a request header (e.g. content or a
 * redirect negotiated from `Accept-Language`) must advertise that with `Vary`,
 * or a shared cache keyed on the URL alone can serve one visitor's variant to
 * another. This merges field-names into any existing `Vary` value rather than
 * overwriting it, so a prior `Vary: Cookie` is preserved.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final class VaryHeader
{
    /**
     * Merge $fieldNames into an existing `Vary` header value.
     *
     * Tokens are de-duplicated case-insensitively and order is preserved
     * (existing tokens first). Each argument may itself be a comma-separated
     * list. When any token is `*` (RFC 9110: vary on everything) the result
     * collapses to `*`. Returns the empty string when nothing varies.
     */
    #[NoDiscard]
    public static function merge(string $existing, string ...$fieldNames): string
    {
        $tokens = [];
        $seen = [];

        foreach ([$existing, ...$fieldNames] as $chunk) {
            foreach (explode(',', $chunk) as $raw) {
                $token = trim($raw);

                if ($token === '') {
                    continue;
                }

                if ($token === '*') {
                    return '*';
                }

                $key = strtolower($token);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $tokens[] = $token;
            }
        }

        return implode(', ', $tokens);
    }
}
