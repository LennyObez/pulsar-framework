<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support\Http;

use FilesystemIterator;
use Pulsar\Http\Method;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_merge;
use function ctype_space;
use function file_get_contents;
use function preg_match;
use function preg_match_all;
use function preg_replace_callback;
use function preg_split;
use function rtrim;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function str_split;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtoupper;
use function strtr;
use function substr;
use function substr_count;
use function trim;

use const DIRECTORY_SEPARATOR;
use const PREG_OFFSET_CAPTURE;
use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * Finds every `<form>` a shipped view can render, and the request it submits.
 *
 * The scanner reads templates as text rather than rendering them, because the
 * question it answers — "can this form's target accept this form's method?" —
 * is decided by the route table and the markup alone. Interpolated runs
 * (`{{ ... }}`, `<?= ... ?>`, `{$var}` inside a heredoc) collapse to a
 * placeholder segment that is flagged dynamic; a ternary spanning the whole
 * attribute expands into one candidate per branch, so the create and update
 * targets of a shared form are both checked.
 *
 * String literals nested inside a sub-expression stay dynamic: in
 * `$item['id']` the `'id'` belongs to the array access, not to the URL.
 */
final class FormActionScanner
{
    /**
     * Matches an interpolated run in either template dialect.
     */
    private const string EXPRESSION_PATTERN = '/(\{\{.*?}}|<\?=.*?\?>|<\?php.*?\?>|\{\$[^}]*})/s';

    /**
     * Scan a directory tree for form elements.
     *
     * `.pulsar.php` files are skipped: the template engine resolves only
     * `.pulse.php`, so those files are unreachable copies.
     *
     * @param string $directory Absolute path to scan
     *
     * @return list<DiscoveredForm> Every form found, in file then line order
     */
    public function scanDirectory(string $directory): array
    {
        $forms = [];

        foreach ($this->phpFilesIn($directory) as $file) {
            foreach ($this->scanFile($file, $directory) as $form) {
                $forms[] = $form;
            }
        }

        return $forms;
    }

    /**
     * @return list<string> Absolute paths, sorted for a stable report order
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        /** @var SplFileInfo $entry */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        ) as $entry) {
            $path = strtr($entry->getPathname(), DIRECTORY_SEPARATOR, '/');

            if (!$entry->isFile() || !str_ends_with($path, '.php')) {
                continue;
            }

            if (str_ends_with($path, '.pulsar.php') || str_contains($path, '/tests/')) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<DiscoveredForm>
     */
    private function scanFile(string $path, string $directory): array
    {
        $source = file_get_contents($path);

        if ($source === false || !str_contains($source, '<form')) {
            return [];
        }

        /*
         * Blank out interpolated runs before locating the tag's closing `>`: a
         * `<?= $e($x) ?>` inside an attribute value carries a `>` of its own and
         * would otherwise cut the tag short, hiding the attributes after it.
         */
        $masked = preg_replace_callback(
            self::EXPRESSION_PATTERN,
            static fn(array $match): string => str_repeat("\x01", strlen((string) $match[0])),
            $source,
        ) ?? $source;

        if (preg_match_all('/<form\b/i', $masked, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $relative = substr($path, strlen(strtr($directory, DIRECTORY_SEPARATOR, '/')) + 1);
        $forms = [];

        /** @var list<array{0: string, 1: int<-1, max>}> $occurrences */
        $occurrences = $matches[0];

        foreach ($occurrences as [, $offset]) {
            $close = strpos($masked, '>', $offset);

            if ($close === false) {
                continue;
            }

            $tag = substr($source, $offset, $close - $offset + 1);
            $action = $this->attribute($tag, 'action');

            if ($action === null || trim($action) === '') {
                continue;
            }

            $method = Method::tryFrom(strtoupper(trim($this->attribute($tag, 'method') ?? 'GET')))
                ?? Method::GET;
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;

            foreach ($this->targetCandidates($action) as $segments) {
                if ($segments === []) {
                    continue;
                }

                $forms[] = new DiscoveredForm($relative, $line, $method, $action, $segments);
            }
        }

        return $forms;
    }

    private function attribute(string $tag, string $name): ?string
    {
        if (preg_match('/\b' . $name . '\s*=\s*(["\'])(.*?)\1/is', $tag, $matches) !== 1) {
            return null;
        }

        return $matches[2];
    }

    /**
     * Expand an `action` attribute into the concrete paths it can produce.
     *
     * Returns an empty list for a target this scanner cannot resolve to an
     * absolute path — an attribute that is one bare variable, a fragment, or an
     * absolute URL. Those carry no claim about the route table.
     *
     * @return list<list<FormTargetSegment>>
     */
    private function targetCandidates(string $action): array
    {
        $candidates = [];

        foreach ($this->expandParts($action) as $parts) {
            $segments = $this->toSegments($parts);

            if ($segments !== []) {
                $candidates[] = $segments;
            }
        }

        return $candidates;
    }

    /**
     * @return list<list<array{text: string, dynamic: bool}>>
     */
    private function expandParts(string $action): array
    {
        $pieces = preg_split(self::EXPRESSION_PATTERN, $action, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($pieces === false) {
            return [];
        }

        /** @var list<list<array{text: string, dynamic: bool}>> $candidates */
        $candidates = [[]];

        foreach ($pieces as $piece) {
            if ($piece === '') {
                continue;
            }

            $alternatives = preg_match(self::EXPRESSION_PATTERN, $piece) === 1
                ? $this->expressionAlternatives($this->unwrapExpression($piece))
                : [[['text' => $piece, 'dynamic' => false]]];

            $next = [];

            foreach ($candidates as $prefix) {
                foreach ($alternatives as $alternative) {
                    $next[] = array_merge($prefix, $alternative);
                }
            }

            $candidates = $next;
        }

        return $candidates;
    }

    private function unwrapExpression(string $piece): string
    {
        foreach (['{{' => '}}', '<?=' => '?>', '<?php' => '?>', '{$' => '}'] as $open => $close) {
            if (!str_starts_with($piece, $open)) {
                continue;
            }

            $inner = substr($piece, strlen($open), -strlen($close));

            return $open === '{$' ? '$' . $inner : $inner;
        }

        return $piece;
    }

    /**
     * Reduce a PHP expression to the literal/placeholder runs it can emit.
     *
     * A top-level ternary yields one alternative per branch; anything else
     * yields a single run list where top-level string literals survive verbatim
     * and every other term becomes one placeholder marked dynamic.
     *
     * @return list<list<array{text: string, dynamic: bool}>>
     */
    private function expressionAlternatives(string $expression): array
    {
        $expression = trim(rtrim(trim($expression), ';'));
        $branches = $this->splitTernary($expression);

        if ($branches !== null) {
            return array_merge(
                $this->expressionAlternatives($branches[0]),
                $this->expressionAlternatives($branches[1]),
            );
        }

        return [$this->concatenationParts($expression)];
    }

    /**
     * @return array{0: string, 1: string}|null The two branches, or null when this is not a ternary
     */
    private function splitTernary(string $expression): ?array
    {
        $depth = 0;
        $quote = null;
        $question = -1;
        $colon = -1;
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($depth === 0 && $char === '?' && $question === -1 && ($expression[$i + 1] ?? '') !== '?') {
                $question = $i;
            } elseif ($depth === 0 && $char === ':' && $question !== -1 && $colon === -1) {
                $colon = $i;
            }
        }

        if ($question === -1 || $colon === -1) {
            return null;
        }

        return [
            substr($expression, $question + 1, $colon - $question - 1),
            substr($expression, $colon + 1),
        ];
    }

    /**
     * @return list<array{text: string, dynamic: bool}>
     */
    private function concatenationParts(string $expression): array
    {
        $parts = [];
        $literal = '';
        $quote = null;
        $depth = 0;
        $pendingDynamic = false;
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;

                    if ($depth === 0) {
                        $parts[] = ['text' => $literal, 'dynamic' => false];
                    }

                    $literal = '';

                    continue;
                }

                $literal .= $char;

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;

                if ($depth === 0 && $pendingDynamic) {
                    $parts[] = ['text' => '1', 'dynamic' => true];
                    $pendingDynamic = false;
                }

                continue;
            }

            if ($char === '.' && $depth === 0) {
                if ($pendingDynamic) {
                    $parts[] = ['text' => '1', 'dynamic' => true];
                    $pendingDynamic = false;
                }

                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
                $pendingDynamic = true;

                continue;
            }

            if ($char === ')' || $char === ']') {
                $depth--;

                continue;
            }

            if (!ctype_space($char)) {
                $pendingDynamic = true;
            }
        }

        if ($pendingDynamic) {
            $parts[] = ['text' => '1', 'dynamic' => true];
        }

        return $parts;
    }

    /**
     * Split concatenated runs into path segments, carrying the dynamic flag.
     *
     * Returns an empty list unless the target is an absolute same-origin path:
     * a relative target or an `https://` URL says nothing about this route table.
     *
     * @param list<array{text: string, dynamic: bool}> $parts
     *
     * @return list<FormTargetSegment>
     */
    private function toSegments(array $parts): array
    {
        $path = '';

        foreach ($parts as $part) {
            $path .= $part['text'];
        }

        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return [];
        }

        /** @var list<FormTargetSegment> $segments */
        $segments = [];
        $text = '';
        $dynamic = false;
        $started = false;

        foreach ($parts as $part) {
            foreach (str_split($part['text']) as $char) {
                if ($char === '/') {
                    if ($started) {
                        $segments[] = new FormTargetSegment($text, $dynamic);
                    }

                    $started = true;
                    $text = '';
                    $dynamic = false;

                    continue;
                }

                if ($char === '?' || $char === '#') {
                    // Query string / fragment: not part of the routed path.
                    $segments[] = new FormTargetSegment($text, $dynamic);

                    return $segments;
                }

                $text .= $char;
                $dynamic = $dynamic || $part['dynamic'];
            }
        }

        $segments[] = new FormTargetSegment($text, $dynamic);

        return $segments;
    }
}
