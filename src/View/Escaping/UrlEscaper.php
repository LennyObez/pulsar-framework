<?php

declare(strict_types=1);

namespace Pulsar\View\Escaping;

use Pulsar\Api\Api;

use function in_array;
use function ord;
use function preg_match;
use function rawurlencode;
use function strlen;
use function strtolower;
use function trim;

/**
 * URL context escaper.
 *
 * URL-encodes values for use in href/src attributes. Validates against
 * dangerous URI schemes (javascript:, data:, vbscript:) to prevent XSS.
 */
#[Api(since: '1.0.0')]
final readonly class UrlEscaper implements EscaperInterface
{
    /** @var list<string> Dangerous URI schemes that must be blocked */
    private const array DANGEROUS_SCHEMES = [
        'javascript',
        'data',
        'vbscript',
    ];

    public function escape(string $value): string
    {
        $trimmed = trim($value);

        if ($this->isDangerousScheme($trimmed)) {
            return '';
        }

        // If it looks like an absolute URL (has scheme), encode only query/fragment parts
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+\-.]*://#', $trimmed) === 1) {
            return $this->encodeUrl($trimmed);
        }

        // Relative URL or path — encode safely
        return $this->encodeUrl($trimmed);
    }

    private function isDangerousScheme(string $value): bool
    {
        // Strip ASCII control chars, whitespace, and Unicode whitespace/formatting
        // characters that browsers may normalize away when parsing URI schemes.
        // This includes: null bytes, tabs, newlines, spaces, zero-width spaces,
        // zero-width joiners, BOM, soft hyphens, and other invisible characters.
        // Multi-byte UTF-8 sequences must be matched as complete units.
        $normalized = preg_replace(
            '/[\x00-\x20\x7F]|'                       // ASCII control + space + DEL
            . '\xC2[\xA0\xAD]|'                        // U+00A0 (NBSP) + U+00AD (soft hyphen)
            . '\xE2\x80[\x8B-\x8F\xA8\xA9\xAA\xAB]|' // U+200B-200F, U+2028-202B
            . '\xE2\x81[\xA0-\xAF]|'                   // U+2060-206F (invisible formatting)
            . '\xEF\xBB\xBF/s',                        // U+FEFF (BOM)
            '',
            $value,
        ) ?? $value;
        $lower = strtolower($normalized);

        foreach (self::DANGEROUS_SCHEMES as $scheme) {
            if (str_starts_with($lower, $scheme . ':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Encode a URL, preserving structure characters but encoding unsafe characters.
     */
    private function encodeUrl(string $url): string
    {
        // Split into parts and encode each segment while preserving structure
        $encoded = '';
        $length = strlen($url);

        for ($i = 0; $i < $length; $i++) {
            $char = $url[$i];
            $ord = ord($char);

            // Preserve URL-safe characters: A-Z a-z 0-9 - _ . ~ : / ? # [ ] @ ! $ & ' ( ) * + , ; = %
            if (
                ($ord >= 0x41 && $ord <= 0x5A) // A-Z
                || ($ord >= 0x61 && $ord <= 0x7A) // a-z
                || ($ord >= 0x30 && $ord <= 0x39) // 0-9
                || in_array($char, ['-', '_', '.', '~', ':', '/', '?', '#', '[', ']', '@', '!', '$', '&', "'", '(', ')', '*', '+', ',', ';', '=', '%'], true)
            ) {
                $encoded .= $char;
            } else {
                $encoded .= rawurlencode($char);
            }
        }

        return $encoded;
    }
}
