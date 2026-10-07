<?php

declare(strict_types=1);

namespace Pulsar\View\Engine;

use PhpToken;
use Pulsar\Api\Internal;

use function array_values;
use function count;
use function ctype_alnum;
use function ctype_alpha;
use function implode;
use function in_array;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function str_repeat;
use function strlen;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use function substr_count;

use const PREG_OFFSET_CAPTURE;
use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_FUNCTION;
use const T_INLINE_HTML;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_STRING;
use const T_WHITESPACE;

/**
 * Finds every place in a Pulse template where a translated string would reach
 * the output without being escaped.
 *
 * The translation helpers (`t`, `tRaw`, `trans`, `__`, `i18n`) all return the
 * raw catalog string. Escaping happens in the template layer, and only on two
 * paths: the `@t` / `@i18n` directive, which wraps the call in
 * `htmlspecialchars()`, and the `{{ }}` echo, which wraps its expression in
 * `ContextEscaper::html()`. Every other route from a helper to the response
 * body emits the catalog string verbatim.
 *
 * The trap this guard closes is that the *same seven characters* — `@t('k')` —
 * compile down one of three different paths depending only on what surrounds
 * them:
 *
 *  - in markup, `@t('k')` is the directive, and is escaped;
 *  - inside the template's own PHP tags, `<?= @t('k') ?>`, the `@` is PHP's
 *    error-suppression operator and `t()` is called directly — unescaped;
 *  - inside another directive's argument list, `@section('title', @t('k'))`,
 *    the inner `@` is again suppression, and the value is stored and later
 *    echoed by `@yield`, which does not escape.
 *
 * A construct whose escaping depends on its surroundings cannot be reviewed by
 * reading it, so the compiler refuses it outright. This class produces the
 * facts; the compiler and the repository gate only report what it measured.
 */
#[Internal(reason: 'Compiler safety check, not part of the public API')]
final class TranslationOutputGuard
{
    /**
     * Global helpers that return an unescaped translated string.
     *
     * `i18n` is listed because it is also a directive name, and an author who
     * writes `<?= @i18n('k') ?>` gets a fatal "undefined function" instead of
     * the escaping they asked for — the same confusion, a different wreck.
     *
     * @var list<string>
     */
    private const array HELPERS = ['t', 'traw', 'trans', '__', 'i18n'];

    /** Regex alternation for the same names, matched case-insensitively. */
    private const string HELPER_PATTERN = 't|tRaw|trans|__|i18n';

    /**
     * Collect every unescaped-translation construct in a template source.
     *
     * @return list<TranslationOutputViolation>
     */
    public function violations(string $source): array
    {
        // The compiler strips comments before anything else, so a commented-out
        // construct never compiles and is not a violation.
        $scannable = $this->normalizePhpBlocks($this->blankComments($source));

        // array_values pins the list shape the loop and the neighbour lookups
        // rely on; PhpToken::tokenize is documented as array<PhpToken>.
        $tokens = array_values(PhpToken::tokenize($scannable));
        $count = count($tokens);
        $violations = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_INLINE_HTML)) {
                $this->scanMarkup($token->text, $token->line, $violations);

                continue;
            }

            $this->scanPhpCall($tokens, $i, $violations);
        }

        return $violations;
    }

    /**
     * Render a violation list as one compiler diagnostic line.
     *
     * @param list<TranslationOutputViolation> $violations
     */
    public function describe(array $violations): string
    {
        $lines = [];

        foreach ($violations as $violation) {
            $lines[] = $violation->describe();
        }

        return 'unescaped translation output — ' . implode('; ', $lines);
    }

    /**
     * Report a helper call sitting inside one of the template's own PHP tags.
     *
     * PHP's lexer decides where those runs are, so a `t(` inside a string
     * literal, a comment or a heredoc is not a call and is not reported.
     *
     * @param list<PhpToken> $tokens
     * @param list<TranslationOutputViolation> $violations
     *
     * @param-out list<TranslationOutputViolation> $violations
     */
    private function scanPhpCall(array $tokens, int $index, array &$violations): void
    {
        $token = $tokens[$index];

        if (!$token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            return;
        }

        $name = $this->baseName($token->text);

        if (!in_array($name, self::HELPERS, true)) {
            return;
        }

        $next = $this->significant($tokens, $index + 1, 1);

        if ($next === null || $next->text !== '(') {
            return;
        }

        $previous = $this->significant($tokens, $index - 1, -1);

        // Method calls, class constants and declarations are not the helper.
        if ($previous !== null
            && ($previous->text === '->' || $previous->text === '?->' || $previous->text === '::' || $previous->is(T_FUNCTION))
        ) {
            return;
        }

        /*
         * A short echo of `@t('k')` reads as the directive but is a suppressed
         * call, so the diagnostic quotes the `@` back to the author when it is
         * there. (A `?` followed by `>` cannot appear in a line comment: it
         * would close PHP mode mid-file.)
         */
        $suppressed = $previous !== null && $previous->text === '@';

        $violations[] = new TranslationOutputViolation(
            $token->line,
            ($suppressed ? '@' : '') . $token->text . '(',
            'a translation helper called inside a PHP tag returns the catalog string '
            . 'unescaped'
            . ($suppressed ? ' (the leading @ is PHP error suppression, not the directive)' : '')
            . '; drop the PHP tag and write the @t directive in markup instead, or @tRaw '
            . 'when the translation is deliberately markup',
        );
    }

    /**
     * Report the ambiguous `@helper(` form inside one inline-HTML run.
     *
     * At the top level of markup `@t('k')` is the directive and is escaped, so
     * this walk mirrors the compiler's own: it consumes `{{ }}`, `{!! !!}` and
     * whole directive argument lists, and reports a helper reached through any
     * of them.
     *
     * @param list<TranslationOutputViolation> $violations
     *
     * @param-out list<TranslationOutputViolation> $violations
     */
    private function scanMarkup(string $markup, int $startLine, array &$violations): void
    {
        $length = strlen($markup);
        $offset = 0;

        while ($offset < $length) {
            if (substr($markup, $offset, 3) === '{!!') {
                $end = strpos($markup, '!!}', $offset + 3);

                if ($end === false) {
                    $offset += 3;

                    continue;
                }

                $this->scanEcho(
                    substr($markup, $offset + 3, $end - $offset - 3),
                    $startLine + $this->lineOffset($markup, $offset + 3),
                    true,
                    $violations,
                );
                $offset = $end + 3;

                continue;
            }

            if (substr($markup, $offset, 2) === '{{') {
                $end = strpos($markup, '}}', $offset + 2);

                if ($end === false) {
                    $offset += 2;

                    continue;
                }

                $this->scanEcho(
                    substr($markup, $offset + 2, $end - $offset - 2),
                    $startLine + $this->lineOffset($markup, $offset + 2),
                    false,
                    $violations,
                );
                $offset = $end + 2;

                continue;
            }

            if ($markup[$offset] !== '@') {
                $offset++;

                continue;
            }

            $nameEnd = $this->identifierEnd($markup, $offset + 1);

            if ($nameEnd === $offset + 1) {
                $offset++;

                continue;
            }

            $cursor = $nameEnd;

            while ($cursor < $length && ($markup[$cursor] === ' ' || $markup[$cursor] === "\t")) {
                $cursor++;
            }

            if ($cursor >= $length || $markup[$cursor] !== '(') {
                $offset = $nameEnd;

                continue;
            }

            $expression = $this->balancedExpression($markup, $cursor);

            $this->reportSuppressed(
                $expression,
                $startLine + $this->lineOffset($markup, $cursor + 1),
                'a directive argument list',
                $violations,
            );

            $offset = $cursor + strlen($expression) + 2;
        }
    }

    /**
     * Report helpers reached through a `{{ }}` or `{!! !!}` echo body.
     *
     * @param list<TranslationOutputViolation> $violations
     *
     * @param-out list<TranslationOutputViolation> $violations
     */
    private function scanEcho(string $body, int $startLine, bool $raw, array &$violations): void
    {
        $this->reportSuppressed(
            $body,
            $startLine,
            $raw ? 'a {!! !!} echo' : 'a {{ }} echo',
            $violations,
        );

        if (!$raw) {
            return;
        }

        $matches = [];
        preg_match_all(
            '/(?<![\w$>])\\\\?(' . self::HELPER_PATTERN . ')\b\s*\(/i',
            $body,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($matches[0] as $match) {
            $violations[] = new TranslationOutputViolation(
                $startLine + $this->lineOffset($body, $match[1]),
                '{!! ' . $match[0] . ' … !!}',
                '{!! !!} echoes its expression verbatim, so the catalog string reaches the '
                . 'response unescaped; write @t in markup, or @tRaw when the translation '
                . 'is deliberately markup',
            );
        }
    }

    /**
     * Report every `@helper(` occurrence inside a span where the leading `@` is
     * PHP's error-suppression operator rather than a directive marker.
     *
     * @param list<TranslationOutputViolation> $violations
     *
     * @param-out list<TranslationOutputViolation> $violations
     */
    private function reportSuppressed(string $span, int $startLine, string $context, array &$violations): void
    {
        $matches = [];
        preg_match_all(
            '/@(' . self::HELPER_PATTERN . ')\b\s*\(/i',
            $span,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($matches[0] as $match) {
            $violations[] = new TranslationOutputViolation(
                $startLine + $this->lineOffset($span, $match[1]),
                $match[0],
                'inside ' . $context . ' the leading @ is PHP error suppression, not the '
                . 'directive, so nothing escapes the translation; drop the @ so the '
                . 'surrounding escape applies, or move the text into a @section block '
                . 'where the @t directive runs on its own',
            );
        }
    }

    /**
     * Replace `{{-- … --}}` comments with blanks, preserving offsets and lines.
     */
    private function blankComments(string $source): string
    {
        return preg_replace_callback(
            '/\{\{--.*?--}}/s',
            static function (array $matches): string {
                /** @var array{0: string} $matches */
                $text = $matches[0];
                $newlines = substr_count($text, "\n");

                return str_repeat(' ', strlen($text) - $newlines) . str_repeat("\n", $newlines);
            },
            $source,
        ) ?? $source;
    }

    /**
     * Turn `@php` / `@endphp` blocks into the PHP tags the compiler emits for
     * them, so the PHP-run scan sees their bodies as the PHP they become.
     *
     * Neither replacement adds or removes a newline, so reported line numbers
     * still refer to the original source.
     */
    private function normalizePhpBlocks(string $source): string
    {
        $result = '';

        foreach (PhpToken::tokenize($source) as $token) {
            if (!$token->is(T_INLINE_HTML)) {
                $result .= $token->text;

                continue;
            }

            $text = preg_replace('/@endphp\b/', '?>     ', $token->text) ?? $token->text;
            $result .= preg_replace('/@php\b/', '<?php', $text) ?? $text;
        }

        return $result;
    }

    /**
     * Strip any namespace qualifier and lowercase, giving the callable name.
     */
    private function baseName(string $text): string
    {
        $separator = strrpos($text, '\\');

        return strtolower($separator === false ? $text : substr($text, $separator + 1));
    }

    /**
     * The nearest token from $from in $direction that is not whitespace or a comment.
     *
     * @param list<PhpToken> $tokens
     */
    private function significant(array $tokens, int $from, int $direction): ?PhpToken
    {
        $count = count($tokens);

        for ($i = $from; $i >= 0 && $i < $count; $i += $direction) {
            if (!$tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /**
     * End offset of the identifier starting at $start, or $start if there is none.
     */
    private function identifierEnd(string $text, int $start): int
    {
        $length = strlen($text);

        if ($start >= $length || !ctype_alpha($text[$start])) {
            return $start;
        }

        $end = $start + 1;

        while ($end < $length && (ctype_alnum($text[$end]) || $text[$end] === '_')) {
            $end++;
        }

        return $end;
    }

    /**
     * Content between the parentheses opening at $openPos, quote-aware, using
     * the same balancing rule as the compiler so both agree on where a
     * directive's argument list ends.
     */
    private function balancedExpression(string $source, int $openPos): string
    {
        $depth = 0;
        $length = strlen($source);
        $quote = null;

        for ($i = $openPos; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $openPos + 1, $i - $openPos - 1);
                }
            }
        }

        return substr($source, $openPos + 1);
    }

    /**
     * Number of newlines before $offset, i.e. how far down $text a position is.
     */
    private function lineOffset(string $text, int $offset): int
    {
        return substr_count(substr($text, 0, $offset), "\n");
    }
}
