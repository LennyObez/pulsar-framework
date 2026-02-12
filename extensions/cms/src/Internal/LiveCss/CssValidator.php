<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\LiveCss;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\LiveCss\CssValidationResult;
use Pulsar\Extension\Cms\LiveCss\CssValidatorInterface;

use function implode;
use function preg_match;
use function preg_replace;
use function str_contains;
use function str_replace;
use function strlen;
use function strtolower;
use function trim;

/**
 * Security-focused CSS validator that rejects dangerous constructs.
 *
 * Scans line-by-line with regex patterns, handling case-insensitive matching,
 * whitespace obfuscation, CSS comments, backslash escapes, and encoded values.
 */
#[Internal(reason: 'Use CssValidatorInterface for public API')]
final readonly class CssValidator implements CssValidatorInterface
{
    /** Maximum allowed CSS input size (512 KB). */
    private const int MAX_CSS_SIZE = 524_288;

    public function validate(string $cssContent): CssValidationResult
    {
        $errors = [];
        $sanitizedLines = [];

        if (strlen($cssContent) > self::MAX_CSS_SIZE) {
            return new CssValidationResult(
                isValid: false,
                errors: ['CSS content exceeds maximum allowed size of 512 KB'],
                sanitizedCss: '',
            );
        }

        // Strip CSS comments before scanning to prevent comment-based bypass
        $stripped = $this->stripCssComments($cssContent);

        // Remove null bytes that can evade pattern matching
        $stripped = str_replace("\0", '', $stripped);

        // Remove backslash escapes that could hide patterns (e.g. \65xpression → expression)
        $unescaped = $this->removeBackslashEscapes($stripped);

        $lines = explode("\n", $unescaped);

        foreach ($lines as $lineNumber => $line) {
            $lineNum = $lineNumber + 1;
            $normalized = strtolower(trim($line));

            // Collapse all whitespace (including non-breaking space, tabs) for detection
            $compacted = (string) preg_replace('/[\s\x{00A0}]+/u', '', $normalized);

            if ($this->containsImport($compacted)) {
                $errors[] = "Line {$lineNum}: @import is not allowed";

                continue;
            }

            if ($this->containsCharset($compacted)) {
                $errors[] = "Line {$lineNum}: @charset is not allowed";

                continue;
            }

            if ($this->containsExpression($compacted)) {
                $errors[] = "Line {$lineNum}: expression() is not allowed";

                continue;
            }

            if ($this->containsExternalUrl($compacted)) {
                $errors[] = "Line {$lineNum}: url() with external scheme is not allowed";

                continue;
            }

            if ($this->containsJavascript($compacted)) {
                $errors[] = "Line {$lineNum}: javascript: protocol is not allowed";

                continue;
            }

            if ($this->containsVbscript($compacted)) {
                $errors[] = "Line {$lineNum}: vbscript: protocol is not allowed";

                continue;
            }

            if ($this->containsMozBinding($compacted)) {
                $errors[] = "Line {$lineNum}: -moz-binding is not allowed";

                continue;
            }

            if ($this->containsBehavior($compacted)) {
                $errors[] = "Line {$lineNum}: behavior: is not allowed";

                continue;
            }

            if ($this->containsOLink($compacted)) {
                $errors[] = "Line {$lineNum}: -o-link: is not allowed";

                continue;
            }

            $sanitizedLines[] = $line;
        }

        $sanitizedCss = implode("\n", $sanitizedLines);

        return new CssValidationResult(
            isValid: $errors === [],
            errors: $errors,
            sanitizedCss: $sanitizedCss,
        );
    }

    private function containsImport(string $line): bool
    {
        // Whitespace already stripped in $compacted
        return str_contains($line, '@import');
    }

    private function containsCharset(string $line): bool
    {
        return str_contains($line, '@charset');
    }

    private function containsExpression(string $line): bool
    {
        return str_contains($line, 'expression(');
    }

    private function containsExternalUrl(string $line): bool
    {
        // Match url() containing http:, https:, ftp:, data:, javascript:, vbscript:,
        // or protocol-relative //
        return (bool) preg_match('/url\(["\']?(https?:|ftp:|data:|javascript:|vbscript:|\/\/)/i', $line);
    }

    private function containsJavascript(string $line): bool
    {
        return str_contains($line, 'javascript:');
    }

    private function containsVbscript(string $line): bool
    {
        return str_contains($line, 'vbscript:');
    }

    private function containsMozBinding(string $line): bool
    {
        return str_contains($line, '-moz-binding');
    }

    private function containsBehavior(string $line): bool
    {
        return str_contains($line, 'behavior:');
    }

    private function containsOLink(string $line): bool
    {
        return str_contains($line, '-o-link');
    }

    /**
     * Strip all CSS block comments to prevent comment-based obfuscation.
     */
    private function stripCssComments(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /**
     * Remove CSS backslash escape sequences (e.g. \65 → e, \0065 → e)
     * to prevent escaped character bypass of pattern matching.
     */
    private function removeBackslashEscapes(string $css): string
    {
        // Remove hex escape sequences: \XX or \XXXXXX followed by optional space
        $result = (string) preg_replace_callback(
            '/\\\\([0-9a-fA-F]{1,6})\s?/',
            static fn(array $matches): string => mb_chr((int) hexdec($matches[1])),
            $css,
        );

        // Remove simple character escapes: \c where c is not a hex digit
        return (string) preg_replace('/\\\\([^0-9a-fA-F])/', '$1', $result);
    }
}
