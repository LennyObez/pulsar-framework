<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_shift;
use function array_slice;
use function basename;
use function count;
use function dirname;
use function end;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function preg_match;
use function preg_match_all;
use function rtrim;
use function scandir;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function substr_count;
use function token_get_all;
use function trim;

use const DIRECTORY_SEPARATOR;
use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;
use const T_ABSTRACT;
use const T_ATTRIBUTE;
use const T_CLASS;
use const T_COMMENT;
use const T_CURLY_OPEN;
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_ENUM;
use const T_FINAL;
use const T_FUNCTION;
use const T_INTERFACE;
use const T_PRIVATE;
use const T_PROTECTED;
use const T_PUBLIC;
use const T_READONLY;
use const T_STATIC;
use const T_STRING;
use const T_TRAIT;
use const T_USE;
use const T_WHITESPACE;

/**
 * The ASVS matrix is the page most likely to be believed and least able to defend itself.
 *
 * It is hand-written markdown about a moving tree, and for a long stretch twenty-eight of
 * its rows carried a ✅ beside nothing but a module name — `src/Auth/**`, `SessionConfig`,
 * "MIME sniffing". That is the exact failure the compliance system refuses to allow in
 * code (ADR-0045: a status is observed, never written), reproduced by hand on a security
 * page, where an assessor reads the tick and stops.
 *
 * These cases hold the page to the definition it now states: a ✅ names the file and line
 * the control lives at, and the test that fails when the control is removed. They grade
 * citations, not correctness — no test can read a row and decide whether the clause is
 * genuinely satisfied. What they can do is fail the moment a row stops pointing at
 * something that exists, which is how the twenty-eight got there in the first place:
 * nothing was ever going to notice.
 *
 * Paths are checked in the Control column only. The Status column carries the page's
 * record of citations that were withdrawn — three of them name paths that were deleted or
 * never existed, and say so — and a check that could not tell a withdrawal from a claim
 * would push the page into deleting its own history to stay green.
 */
#[CoversNothing]
final class AsvsMatrixClaimsTest extends TestCase
{
    private const string MATRIX = 'docs/security/asvs-l2-matrix.md';

    /** Repository directories a backticked token may legitimately point into. */
    private const array EVIDENCE_ROOTS = [
        'src/',
        'tests/',
        'tools/',
        'extensions/',
        'config/',
        'scripts/',
        'docs/',
        '.github/',
        'benchmarks/',
        'resources/',
        'database/',
    ];

    /**
     * Tokens a method declaration may be preceded by without the citation
     * leaving it.
     *
     * A row that cites the `#[Override]` line, the doc block or the visibility
     * keyword is citing the method, and demanding the `function` line itself
     * would turn a correct citation into a failure the first time an attribute
     * is added above it.
     *
     * @var list<int>
     */
    private const array DECLARATION_PREFIX_TOKENS = [
        T_ABSTRACT,
        T_COMMENT,
        T_DOC_COMMENT,
        T_FINAL,
        T_PRIVATE,
        T_PROTECTED,
        T_PUBLIC,
        T_READONLY,
        T_STATIC,
    ];

    /**
     * Base-name index of every PHP file under the evidence roots, built once.
     *
     * @var array<string, list<string>>|null
     */
    private ?array $phpFilesByName = null;

    /**
     * A ✅ without a line number is a pointer to a module, not evidence.
     */
    #[Test]
    public function everyImplementedRowCitesAFileAndLine(): void
    {
        $offenders = [];

        foreach ($this->rows() as [$clause, $control, $status]) {
            if (!str_starts_with($status, '✅')) {
                continue;
            }

            if (preg_match('#[A-Za-z0-9_/.-]+\.(?:php|yml|sh|json|neon)`?:\d#', $control) !== 1) {
                $offenders[] = $clause;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'These rows are marked implemented but cite no file:line: ' . implode(', ', $offenders),
        );
    }

    /**
     * A control nobody watched refusing is indistinguishable from no control (ADR-0060),
     * so a ✅ has to name the test that would fail without it.
     */
    #[Test]
    public function everyImplementedRowCitesATest(): void
    {
        $offenders = [];

        foreach ($this->rows() as [$clause, $control, $status]) {
            if (!str_starts_with($status, '✅')) {
                continue;
            }

            if (!str_contains($control, 'Test.php')) {
                $offenders[] = $clause;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'These rows are marked implemented but name no test: ' . implode(', ', $offenders),
        );
    }

    /**
     * A citation that no longer resolves is worse than none: it reads as checked.
     */
    #[Test]
    public function everyPathCitedAsEvidenceIsInTheTree(): void
    {
        $root = $this->repositoryRoot();
        $missing = [];

        foreach ($this->rows() as [$clause, $control]) {
            foreach ($this->backtickedTokens($control) as $token) {
                $path = $this->asRepositoryPath($token);

                if ($path === null) {
                    continue;
                }

                $absolute = $root . DIRECTORY_SEPARATOR . $path;

                if (!is_file($absolute) && !is_dir($absolute)) {
                    $missing[] = $clause . ' -> ' . $path;
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            'The matrix cites paths that are not in the tree: ' . implode('; ', $missing),
        );
    }

    /**
     * Test files are cited by bare name across the page, so resolve them by name.
     */
    #[Test]
    public function everyTestFileCitedAsEvidenceExists(): void
    {
        $known = $this->testFileNames();
        $missing = [];

        foreach ($this->rows() as [$clause, $control]) {
            preg_match_all('#`([^`]*?[A-Za-z0-9_]+Test\.php)(?::[0-9,\-]+)?`#', $control, $matches);

            foreach ($matches[1] as $cited) {
                $segments = explode('/', $cited);
                $name = $segments[count($segments) - 1];

                if (!isset($known[$name])) {
                    $missing[] = $clause . ' -> ' . $cited;
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            'The matrix cites tests that do not exist: ' . implode('; ', $missing),
        );
    }

    /**
     * The page lists the rows it had to downgrade. That list is a claim like any other.
     *
     * If a downgraded row is quietly promoted back to ✅ without its explanation being
     * removed, the page contradicts itself in the one place a reader goes to find out how
     * far to trust it.
     */
    #[Test]
    public function theDowngradeTableNamesOnlyRowsThatAreNotImplemented(): void
    {
        $statuses = [];

        foreach ($this->rows() as [$clause, , $status]) {
            $statuses[$this->clauseId($clause)] = $status;
        }

        preg_match_all('#^\| `(V[0-9.]+)`\s*\|#m', $this->matrix(), $matches);

        self::assertNotSame(
            [],
            $matches[1],
            'The downgrade table is gone; either restore it or rewrite the section that promises it',
        );

        $contradictions = [];

        foreach ($matches[1] as $downgraded) {
            self::assertArrayHasKey(
                $downgraded,
                $statuses,
                sprintf('The downgrade table names %s, which has no row in the matrix', $downgraded),
            );

            if (str_starts_with($statuses[$downgraded], '✅')) {
                $contradictions[] = $downgraded;
            }
        }

        self::assertSame(
            [],
            $contradictions,
            'These rows are listed as downgraded but are marked implemented: ' . implode(', ', $contradictions),
        );
    }

    /**
     * A citation that names a method has to land inside that method.
     *
     * `everyPathCitedAsEvidenceIsInTheTree()` above resolves paths, and only
     * paths. That is why rows of this page could name a method the cited line
     * has not been inside for several refactors and stay green: the file still
     * existed, so nothing looked further. Some of them drifted under the edits
     * on this branch and the rest had been wrong for longer, including through
     * the pass that claimed to give those rows real citations.
     *
     * A path-only check is the weaker half of the promise this page makes. An
     * assessor following `Kernel::dispatchRoute()` to `src/Core/Kernel.php:857`
     * does not land in `dispatchRoute()`; they land in `addMiddleware()`, read
     * it, and conclude something about verb dispatch from a method that does
     * not dispatch. A citation resolving to the wrong function is worse than no
     * citation, because it is followed.
     *
     * What counts as an anchor: a backticked `Class::method()` or `method()`
     * followed immediately — across whitespace, a comma or an opening
     * parenthesis, and nothing else — by a backticked `path.php:line`, or by a
     * bare `:line` continuing the last path named in the same row. That is the
     * form the page already uses throughout, and the tightness of the separator
     * is what keeps an unrelated line number further along the sentence from
     * being read as this method's.
     *
     * What it asserts, per anchor:
     *
     * - the cited file resolves, by repository path or by unique base name;
     * - a function of that name is declared in it;
     * - the first cited line falls between that declaration's first line —
     *   attributes and doc block included — and its closing brace;
     * - the enclosing class is the one the anchor names, when it names one.
     *
     * The last of those is not redundant. Two of the wrong rows named the right
     * method in the wrong class, because a bare `:line` silently continued a
     * path from the sentence before.
     */
    #[Test]
    public function everyMethodAnchoredCitationLandsInsideTheNamedMethod(): void
    {
        $anchors = $this->methodAnchors();

        self::assertNotSame(
            [],
            $anchors,
            'No method-anchored citation was found; either the matrix stopped citing methods, or '
            . 'its citation form changed under this test',
        );

        $offenders = [];

        foreach ($anchors as $anchor) {
            $failure = $this->explainAnchor($anchor);

            if ($failure !== null) {
                $offenders[] = $failure;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "These citations name a method the cited line is not inside:\n  " . implode("\n  ", $offenders),
        );
    }

    /**
     * Why an anchor does not resolve, or null when it does.
     *
     * @param array{clause: string, class: string, method: string, file: string, line: int, text: string} $anchor
     */
    private function explainAnchor(array $anchor): ?string
    {
        $prefix = $anchor['clause'] . ': ' . $anchor['text'];

        if ($anchor['method'] === '') {
            return $prefix . ' -- names several methods against a different number of line numbers; '
                . 'cite them one at a time so each anchor resolves to a method';
        }

        if ($anchor['file'] === '') {
            return $prefix . ' -- cites a line with no file: no path precedes it in the row';
        }

        $path = $this->resolveCitedFile($anchor['file']);

        if ($path === null) {
            return $prefix . ' -- no single file named ' . $anchor['file'] . ' is in the tree';
        }

        $functions = $this->declaredFunctions($path);
        $named = [];
        $enclosing = null;

        foreach ($functions as $function) {
            if ($function['name'] === $anchor['method']) {
                $named[] = $function;
            }

            if ($anchor['line'] < $function['start'] || $anchor['line'] > $function['end']) {
                continue;
            }

            if ($enclosing === null || $function['start'] > $enclosing['start']) {
                $enclosing = $function;
            }
        }

        if ($named === []) {
            return $prefix . ' -- ' . $anchor['method'] . '() is not declared in ' . $anchor['file'];
        }

        $declared = [];

        foreach ($named as $function) {
            $declared[] = $this->describe($function);

            if ($anchor['line'] < $function['start'] || $anchor['line'] > $function['end']) {
                continue;
            }

            if ($anchor['class'] === '' || $function['class'] === $anchor['class']) {
                return null;
            }

            return $prefix . ' -- line ' . $anchor['line'] . ' is in ' . $this->describe($function)
                . ', not in ' . $anchor['class'] . '::' . $anchor['method'] . '()';
        }

        return $prefix . ' -- line ' . $anchor['line'] . ' is in '
            . ($enclosing === null ? 'no function at all' : $this->describe($enclosing))
            . '; ' . $anchor['method'] . '() is at ' . implode(', ', $declared);
    }

    /**
     * @param array{name: string, class: string, start: int, end: int} $function
     */
    private function describe(array $function): string
    {
        $name = ($function['class'] === '' ? '' : $function['class'] . '::') . $function['name'] . '()';

        return $name . ' (' . $function['start'] . '-' . $function['end'] . ')';
    }

    /**
     * Every method-anchored citation in the matrix.
     *
     * @return list<array{clause: string, class: string, method: string, file: string, line: int, text: string}>
     */
    private function methodAnchors(): array
    {
        $anchors = [];

        foreach ($this->rows() as [$clause, $control]) {
            $files = [];

            preg_match_all(
                '#`([A-Za-z0-9_/.-]+\.php)(?::[0-9,\-]+)?`#',
                $control,
                $pathMatches,
                PREG_OFFSET_CAPTURE,
            );

            foreach ($pathMatches[1] as $capture) {
                $files[$capture[1]] = $capture[0];
            }

            preg_match_all(
                '#((?:`(?:[A-Za-z_][A-Za-z0-9_]*::)?[A-Za-z_][A-Za-z0-9_]*\(\)`[\s,/]{0,3})+)'
                . '\(?`([A-Za-z0-9_/.-]+\.php)?:([0-9][0-9,\-]*)`#',
                $control,
                $anchorMatches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );

            foreach ($anchorMatches as $match) {
                $file = $match[2][0];

                if ($file === '') {
                    $file = $this->lastFileBefore($files, $match[0][1]);
                }

                foreach ($this->pairMethodsWithLines($match[1][0], $match[3][0]) as $pair) {
                    $anchors[] = [
                        'clause' => $this->clauseId($clause),
                        'class' => $pair['class'],
                        'method' => $pair['method'],
                        'file' => $file,
                        'line' => $pair['line'],
                        'text' => $match[0][0],
                    ];
                }
            }
        }

        return $anchors;
    }

    /**
     * Pair the methods named before a citation with the lines named in it.
     *
     * The page writes both forms: one method against one span
     * (`Gate::allows()` (`Gate.php:118-180`)), and a run of methods against a
     * list of lines in the same order
     * (`assertValidName()` / `assertValidValue()` (`HeaderValidator.php:73,97`)).
     * The second form has to be paired positionally, because reading its first
     * number as every method's line is how a correct citation gets reported as
     * broken — which is a worse gate than none, since it teaches the page's
     * authors to work around it.
     *
     * A run whose method count matches neither the number of lines nor one is
     * ambiguous, and it is returned with an empty method so the caller refuses
     * it by name rather than guessing which line belongs to which method.
     *
     * @return list<array{class: string, method: string, line: int}>
     */
    private function pairMethodsWithLines(string $run, string $lineList): array
    {
        preg_match_all(
            '#`(?:([A-Za-z_][A-Za-z0-9_]*)::)?([A-Za-z_][A-Za-z0-9_]*)\(\)`#',
            $run,
            $methodMatches,
            PREG_SET_ORDER,
        );

        $lines = [];

        foreach (explode(',', $lineList) as $entry) {
            $lines[] = (int) explode('-', $entry)[0];
        }

        $methods = count($methodMatches);

        if ($methods !== 1 && $methods !== count($lines)) {
            return [['class' => '', 'method' => '', 'line' => 0]];
        }

        $pairs = [];

        foreach ($methodMatches as $position => $methodMatch) {
            $pairs[] = [
                'class' => $methodMatch[1],
                'method' => $methodMatch[2],
                'line' => $methods === 1 ? $lines[0] : $lines[$position],
            ];
        }

        return $pairs;
    }

    /**
     * The last path cited before this offset in the same row.
     *
     * A bare `:line` continues the file named before it; that is what makes the
     * page readable, and it is also how two of the wrong rows came to point at a
     * method in a different class.
     *
     * @param array<int, string> $files
     */
    private function lastFileBefore(array $files, int $offset): string
    {
        $found = '';
        $best = -1;

        foreach ($files as $position => $file) {
            if ($position < $offset && $position > $best) {
                $best = $position;
                $found = $file;
            }
        }

        return $found;
    }

    /**
     * The absolute path a cited file token names, or null when none does.
     *
     * The page cites some files by repository path and some — tests especially —
     * by bare name, so both have to resolve. A bare name matching more than one
     * file resolves to nothing: the gate must not pick one of them and be right
     * by luck.
     */
    private function resolveCitedFile(string $token): ?string
    {
        foreach (self::EVIDENCE_ROOTS as $evidenceRoot) {
            if (!str_starts_with($token, $evidenceRoot)) {
                continue;
            }

            $absolute = $this->repositoryRoot() . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $token);

            return is_file($absolute) ? $absolute : null;
        }

        $index = $this->phpFilesByName();
        $name = basename($token);

        if (!isset($index[$name]) || count($index[$name]) !== 1) {
            return null;
        }

        return $index[$name][0];
    }

    /**
     * Every PHP file under the evidence roots, indexed by base name.
     *
     * @return array<string, list<string>>
     */
    private function phpFilesByName(): array
    {
        if ($this->phpFilesByName !== null) {
            return $this->phpFilesByName;
        }

        $index = [];

        foreach (self::EVIDENCE_ROOTS as $evidenceRoot) {
            $directory = $this->repositoryRoot() . DIRECTORY_SEPARATOR . rtrim($evidenceRoot, '/');

            if (!is_dir($directory)) {
                continue;
            }

            foreach ($this->collect($directory, '.php') as $path) {
                $index[basename($path)][] = $path;
            }
        }

        self::assertNotSame([], $index, 'No PHP file was found under the evidence roots');

        return $this->phpFilesByName = $index;
    }

    /**
     * Every function declared in a file, with the line span a citation may point
     * at.
     *
     * Read with PHP's own tokenizer rather than with reflection: the matrix
     * cites interface and abstract declarations, files this suite never loads,
     * and files whose class the autoloader would not be asked for, and
     * reflection can answer for none of those. The span runs from the first line
     * of the declaration — attributes and doc block included, see
     * `DECLARATION_PREFIX_TOKENS` — through the line of the closing brace, or of
     * the semicolon where there is no body.
     *
     * @return list<array{name: string, class: string, start: int, end: int}>
     */
    private function declaredFunctions(string $absolutePath): array
    {
        $source = file_get_contents($absolutePath);
        self::assertIsString($source);

        $tokens = token_get_all($source);
        $lines = $this->tokenLines($tokens);

        $functions = [];
        $classes = [];
        $open = [];
        $depth = 0;
        $pendingClass = null;
        $pendingFunction = null;

        foreach ($tokens as $index => $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    $pendingClass = $this->nameAfter($tokens, $index);

                    continue;
                }

                // A brace opened inside an interpolated string closes with an
                // ordinary `}`, so it has to be counted here or every
                // declaration after it is attributed to the wrong depth.
                if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;

                    continue;
                }

                if ($token[0] !== T_FUNCTION || $this->tokenBefore($tokens, $index) === T_USE) {
                    continue;
                }

                $name = $this->nameAfter($tokens, $index);

                if ($name === null) {
                    continue;
                }

                $pendingFunction = [
                    'name' => $name,
                    'class' => $classes === [] ? '' : (string) end($classes),
                    'start' => $this->declarationStart($tokens, $lines, $index),
                ];

                continue;
            }

            if ($token === '{') {
                $depth++;

                if ($pendingClass !== null) {
                    $classes[$depth] = $pendingClass;
                    $pendingClass = null;
                }

                if ($pendingFunction !== null) {
                    $open[$depth][] = $pendingFunction;
                    $pendingFunction = null;
                }

                continue;
            }

            if ($token === ';' && $pendingFunction !== null) {
                $functions[] = [...$pendingFunction, 'end' => $lines[$index]];
                $pendingFunction = null;

                continue;
            }

            if ($token !== '}') {
                continue;
            }

            foreach ($open[$depth] ?? [] as $declaration) {
                $functions[] = [...$declaration, 'end' => $lines[$index]];
            }

            unset($open[$depth], $classes[$depth]);
            $depth--;
        }

        return $functions;
    }

    /**
     * The 1-based line each token starts on.
     *
     * Single-character tokens carry no line of their own, and the closing brace
     * of a method is one of them, so the count is kept by hand.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<int>
     */
    private function tokenLines(array $tokens): array
    {
        $lines = [];
        $line = 1;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $line = $token[2];
            }

            $lines[] = $line;
            $line += substr_count(is_array($token) ? $token[1] : $token, "\n");
        }

        return $lines;
    }

    /**
     * The first line of a declaration, counting back over what belongs to it.
     *
     * @param list<array{int, string, int}|string> $tokens
     * @param list<int>                            $lines
     */
    private function declarationStart(array $tokens, array $lines, int $index): int
    {
        $start = $lines[$index];
        $bracket = 0;

        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if ($bracket > 0) {
                if (is_array($token)) {
                    if ($token[0] === T_ATTRIBUTE) {
                        $bracket--;
                        $start = $lines[$i];
                    }

                    continue;
                }

                if ($token === ']') {
                    $bracket++;
                } elseif ($token === '[') {
                    $bracket--;
                }

                continue;
            }

            if ($token === ']') {
                $bracket = 1;

                continue;
            }

            if (!is_array($token)) {
                break;
            }

            if ($token[0] === T_WHITESPACE) {
                // One newline separates a modifier from what it modifies; two
                // separate one declaration from the one above it.
                if (substr_count($token[1], "\n") > 1) {
                    break;
                }

                continue;
            }

            if (!in_array($token[0], self::DECLARATION_PREFIX_TOKENS, true)) {
                break;
            }

            $start = $lines[$i];
        }

        return $start;
    }

    /**
     * The name declared after a `class`, `interface`, `trait`, `enum` or
     * `function` keyword, or null where there is none — an anonymous class, or a
     * closure.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function nameAfter(array $tokens, int $index): ?string
    {
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            // `function &reference()` puts an ampersand in the way.
            if ($token === '&') {
                continue;
            }

            if (!is_array($token)) {
                return null;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token[0] === T_STRING ? $token[1] : null;
        }

        return null;
    }

    /**
     * The id of the significant token before an index, or null where there is
     * none.
     *
     * Its one job is to tell `use function strlen;` — an import — from a
     * declaration, which would otherwise be reported as a global function
     * spanning a single line and could swallow a citation.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function tokenBefore(array $tokens, int $index): ?int
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                return null;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token[0];
        }

        return null;
    }

    /**
     * Every clause row of the matrix, as [clause, control, status].
     *
     * @return list<array{string, string, string}>
     */
    private function rows(): array
    {
        $rows = [];

        foreach (explode("\n", $this->matrix()) as $line) {
            $line = trim($line);

            if (!str_starts_with($line, '| V')) {
                continue;
            }

            $cells = array_map(trim(...), $this->splitCells($line));
            array_shift($cells);
            $cells = array_slice($cells, 0, count($cells) - 1);

            if (count($cells) !== 3) {
                self::fail('A matrix row does not have three cells: ' . substr($line, 0, 80));
            }

            $rows[] = [$cells[0], $cells[1], $cells[2]];
        }

        self::assertNotSame([], $rows, 'The matrix has no clause rows; its format changed under this test');

        return $rows;
    }

    /**
     * Split a markdown table row on unescaped pipes.
     *
     * Cells here quote shell alternations (`a\|b`), which markdown escapes and a naive
     * explode() would tear in half.
     *
     * @return list<string>
     */
    private function splitCells(string $line): array
    {
        $cells = [];
        $buffer = '';
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $character = $line[$i];

            if ($character === '\\' && $i + 1 < $length) {
                $buffer .= substr($line, $i, 2);
                $i++;

                continue;
            }

            if ($character === '|') {
                $cells[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $character;
        }

        $cells[] = $buffer;

        return $cells;
    }

    /**
     * Every backticked token in a cell.
     *
     * @return list<string>
     */
    private function backtickedTokens(string $cell): array
    {
        preg_match_all('#`([^`]+)`#', $cell, $matches);

        return $matches[1];
    }

    /**
     * A repository-relative path, or null when the token is not one.
     *
     * Cells quote class names, URL paths, regular expressions and glob patterns in the
     * same backticks as file paths. Only tokens rooted in a directory this repository
     * actually has are treated as citations.
     */
    private function asRepositoryPath(string $token): ?string
    {
        $token = trim($token);
        $path = (string) preg_replace('#:[0-9,\-:]+$#', '', $token);

        if (str_contains($path, '*') || str_contains($path, ' ')) {
            return null;
        }

        foreach (self::EVIDENCE_ROOTS as $root) {
            if (str_starts_with($path, $root)) {
                return str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
            }
        }

        return null;
    }

    /**
     * Names of every test class file under the suites this repository runs.
     *
     * @return array<string, true>
     */
    private function testFileNames(): array
    {
        $names = [];

        foreach (['tests', 'extensions'] as $tree) {
            $directory = $this->repositoryRoot() . DIRECTORY_SEPARATOR . $tree;

            if (!is_dir($directory)) {
                continue;
            }

            foreach ($this->collect($directory, 'Test.php') as $path) {
                $names[basename($path)] = true;
            }
        }

        self::assertNotSame([], $names, 'No test files were found; the suite layout changed under this test');

        return $names;
    }

    /**
     * The clause identifier at the head of a row, e.g. `V12.4`.
     */
    private function clauseId(string $clause): string
    {
        if (preg_match('#^(V[0-9.]+?)(?=\s)#', $clause, $matches) !== 1) {
            self::fail('A matrix row does not begin with a clause identifier: ' . $clause);
        }

        return $matches[1];
    }

    /**
     * Every file under a directory whose name ends with the suffix, recursively.
     *
     * scandir rather than RecursiveDirectoryIterator: the SPL iterators yield `mixed`,
     * and narrowing that back to a path costs more code than the recursion does.
     *
     * @return list<string>
     */
    private function collect(string $directory, string $suffix): array
    {
        $entries = scandir($directory);
        self::assertIsArray($entries, 'Could not read ' . $directory);

        $found = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                foreach ($this->collect($path, $suffix) as $nested) {
                    $found[] = $nested;
                }

                continue;
            }

            if (str_ends_with($entry, $suffix)) {
                $found[] = $path;
            }
        }

        return $found;
    }

    private function matrix(): string
    {
        $path = $this->repositoryRoot() . DIRECTORY_SEPARATOR . self::MATRIX;
        self::assertFileExists($path);

        $markdown = file_get_contents($path);
        self::assertIsString($markdown);

        return $markdown;
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
