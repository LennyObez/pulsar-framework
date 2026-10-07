<?php

declare(strict_types=1);

namespace Pulsar\View\Escaping;

use Pulsar\Api\Api;

use function dechex;
use function mb_ord;
use function mb_strlen;
use function mb_substr;

/**
 * CSS context escaper.
 *
 * Escapes values for safe use inside inline style attributes and CSS properties.
 * Encodes all non-alphanumeric characters as CSS hex escapes to prevent injection.
 *
 * Multi-byte UTF-8 characters are properly handled by iterating over
 * codepoints rather than raw bytes.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CssEscaper implements EscaperInterface
{
    public function escape(string $value): string
    {
        $escaped = '';
        $length = mb_strlen($value, 'UTF-8');

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($value, $i, 1, 'UTF-8');
            $ord = mb_ord($char, 'UTF-8');

            if ($ord === false) {
                continue;
            }

            // Keep ASCII alphanumeric characters as-is
            if (
                ($ord >= 0x30 && $ord <= 0x39) // 0-9
                || ($ord >= 0x41 && $ord <= 0x5A) // A-Z
                || ($ord >= 0x61 && $ord <= 0x7A) // a-z
            ) {
                $escaped .= $char;
            } else {
                // CSS hex escape: \XXXXXX (followed by a space to terminate the escape)
                $escaped .= '\\' . dechex($ord) . ' ';
            }
        }

        return $escaped;
    }
}
