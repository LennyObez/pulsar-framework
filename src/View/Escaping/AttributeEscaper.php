<?php

declare(strict_types=1);

namespace Pulsar\View\Escaping;

use Pulsar\Api\Api;

use function mb_ord;
use function mb_strlen;
use function mb_substr;

/**
 * HTML attribute context escaper.
 *
 * Escapes values for safe use inside double-quoted HTML attribute values.
 * More aggressive than standard HTML escaping: encodes all non-alphanumeric
 * characters as numeric HTML entities using Unicode codepoints.
 *
 * Multi-byte UTF-8 characters are properly handled by iterating over
 * codepoints rather than raw bytes.
 */
#[Api(since: '1.0.0')]
final readonly class AttributeEscaper implements EscaperInterface
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
                // Encode everything else as numeric HTML entity (Unicode codepoint)
                $escaped .= '&#' . $ord . ';';
            }
        }

        return $escaped;
    }
}
