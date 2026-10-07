<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Unit\Documentation\Support\TrackedFiles;

use function count;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function implode;
use function is_dir;
use function is_string;
use function json_decode;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function scandir;
use function sort;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use function ucfirst;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const PREG_SET_ORDER;

/**
 * Claims a document makes about this repository, held to the repository.
 *
 * Each case here replaces a sentence that was false when it was found. Not one of them
 * was a typo: each named a mechanism that sounded plausible, described something the tree
 * genuinely contained nearby, and had never been read back against the code. A
 * documentation defect of that shape survives review indefinitely, because reviewing it
 * means going and looking, and nothing ever asks anyone to.
 *
 * So each is written the same way: state the claim, and fail when the tree stops matching
 * it — including when the tree improves. A gate that closes should break the paragraph
 * apologising for it.
 */
#[CoversNothing]
final class DocumentedClaimsTest extends TestCase
{
    /**
     * A `docs/`-rooted path named in prose has to resolve.
     *
     * `docs/prd-1.0.0.md` promised a per-framework control document at
     * `docs/compliance/<framework>.md` and listed nine of them, each marked `(TBD)`. The
     * directory has never existed. Nine paths under a heading read as a documentation set
     * that is nearly finished, which is the opposite of what they were.
     *
     * The decision records are out of scope, and deliberately: an ADR is a dated account
     * of a decision, so a path inside one that has since been deleted is a record of what
     * was true when it was written. Rewriting it would falsify the record — the same
     * reason `tools/ci/assert-mutation-thresholds.php` excludes CHANGELOG.md. Live pages
     * get no such latitude.
     */
    #[Test]
    public function everyDocsPathNamedInProseResolves(): void
    {
        $root = $this->repositoryRoot();
        $missing = [];

        foreach ($this->markdownUnder('docs') as $page) {
            if (str_starts_with($page, 'docs/adr/')) {
                continue;
            }

            $prose = $this->stripCode($this->read($root . DIRECTORY_SEPARATOR . $this->native($page)));

            preg_match_all('/`([^`\n]+)`/', $prose, $spans);

            foreach ($spans[1] as $token) {
                $token = trim($token);

                if (!str_starts_with($token, 'docs/')) {
                    continue;
                }

                $path = (string) preg_replace('/:[0-9,\-]+$/', '', $token);

                if (str_contains($path, '*') || str_contains($path, '<') || str_contains($path, ' ')) {
                    continue;
                }

                if (!file_exists($root . DIRECTORY_SEPARATOR . $this->native($path))) {
                    $missing[] = $page . ' -> ' . $token;
                }
            }
        }

        sort($missing);

        self::assertSame(
            [],
            $missing,
            'Documentation names paths under docs/ that do not exist: ' . implode('; ', $missing),
        );
    }

    /**
     * The claim: `composer bc:check` runs `BcBreakDetector`, in `qa` and in CI.
     *
     * `docs/deprecation-policy.md` asserted that CI ran the detector "automatically on
     * every pull request" for as long as the detector had existed, and no workflow, no
     * `composer` leaf and no script did. The only caller in the tree was the detector's
     * own unit test, so a reader believed a removed `#[Api]` method could not reach
     * `main` unnoticed. It could: eleven unrecorded changes to the stable surface were
     * sitting in the tree when the gate was first run.
     *
     * The predecessor of this test asserted the ABSENCE, and said in its own docblock
     * that landing the wiring must fail it and force the paragraph to be rewritten. That
     * is what happened. This is the rewritten check, pointing the same way: the page now
     * describes a gate, so the gate has to exist, in both places the page names it.
     */
    #[Test]
    public function theBcBreakDetectorIsWiredIntoTheGate(): void
    {
        $policy = $this->read($this->repositoryRoot() . DIRECTORY_SEPARATOR . $this->native('docs/deprecation-policy.md'));

        self::assertStringContainsString(
            '`composer bc:check` runs it.',
            $policy,
            'docs/deprecation-policy.md no longer claims the gate this test verifies',
        );

        $callers = [];

        foreach ($this->wiringSurfaces() as $file) {
            $contents = $this->read($this->repositoryRoot() . DIRECTORY_SEPARATOR . $this->native($file));

            if (str_contains($contents, 'bc:check') || str_contains($contents, 'assert-no-bc-breaks')) {
                $callers[] = $file;
            }
        }

        self::assertContains(
            'composer.json',
            $callers,
            'docs/deprecation-policy.md says `composer bc:check` runs the detector, and composer.json has no such script',
        );

        self::assertNotSame(
            ['composer.json'],
            $callers,
            'docs/deprecation-policy.md says CI runs the detector, and no workflow invokes it',
        );

        self::assertFileExists(
            $this->repositoryRoot() . DIRECTORY_SEPARATOR . $this->native('tools/api/assert-no-bc-breaks.php'),
            'the leaf `composer bc:check` names does not exist',
        );
    }

    /**
     * The claim: the three budget JSON files are reference tables, not gates.
     *
     * `README.md` and `docs/performance.md` both once implied that
     * `tools/php/performance-budgets.json` was what CI enforced. It is not — the
     * `#[Assert]` attributes in `tests/Benchmark` are (ADR-0072). Each file now says so in
     * its own `description`, and this checks the three statements have not drifted apart
     * from each other or from the page.
     */
    #[Test]
    public function theBudgetJsonFilesStillDeclareThemselvesReferenceOnly(): void
    {
        foreach (['performance-budgets.json', 'budgets.fpm.json', 'budgets.persistent.json'] as $name) {
            $decoded = $this->readJson('tools/php/' . $name);

            self::assertArrayHasKey('description', $decoded, $name . ' no longer says what it is');
            self::assertIsString($decoded['description']);
            self::assertStringContainsString(
                'REFERENCE TABLE, NOT ENFORCEMENT',
                $decoded['description'],
                $name . ' no longer states that no code reads it, so docs/performance.md is now unbacked',
            );
        }

        $performance = $this->read($this->repositoryRoot() . DIRECTORY_SEPARATOR . $this->native('docs/performance.md'));

        self::assertStringContainsString(
            'reference table read by no code',
            $performance,
            'docs/performance.md no longer states that the budget JSON is not the gate',
        );
    }

    /**
     * The claim: `budgets.fpm.json` records exactly the figures `EndToEndBench` asserts.
     *
     * `docs/performance.md` tells a reader that the FPM file "has an enforced counterpart
     * even though nothing reads the file itself". That is only worth saying while the two
     * agree, and nothing but this compares them — the file is read by no code, so drift
     * is silent by construction.
     */
    #[Test]
    public function theFpmReferenceBudgetsMatchTheBenchmarkAssertions(): void
    {
        $decoded = $this->readJson('tools/php/budgets.fpm.json');
        self::assertArrayHasKey('overrides', $decoded);
        self::assertIsArray($decoded['overrides']);

        $bench = $this->read(
            $this->repositoryRoot() . DIRECTORY_SEPARATOR . $this->native('tests/Benchmark/EndToEndBench.php'),
        );

        preg_match_all(
            '/#\[Assert\(\'mode\(variant\.time\.avg\) < (\d+) milliseconds\'\)\]\s*public function bench(\w+)\(/',
            $bench,
            $asserted,
            PREG_SET_ORDER,
        );

        self::assertNotSame([], $asserted, 'EndToEndBench declares no millisecond budgets; its shape changed');

        $byMethod = [];

        foreach ($asserted as $match) {
            $byMethod[$match[2]] = (int) $match[1];
        }

        $mismatches = [];

        foreach ($decoded['overrides'] as $key => $override) {
            if (!is_string($key)) {
                continue;
            }

            if (!str_starts_with($key, 'request.')) {
                continue;
            }

            $method = $this->benchMethodFor(substr($key, strlen('request.')));

            if (!isset($byMethod[$method])) {
                $mismatches[] = $key . ' has no benchmark subject bench' . $method;

                continue;
            }

            self::assertIsArray($override);
            self::assertArrayHasKey('max_avg', $override);
            self::assertIsString($override['max_avg']);

            if (preg_match('/^(\d+) milliseconds$/', $override['max_avg'], $found) !== 1) {
                $mismatches[] = $key . ' states "' . $override['max_avg'] . '", which is not a millisecond budget';

                continue;
            }

            if ((int) $found[1] !== $byMethod[$method]) {
                $mismatches[] = sprintf(
                    '%s says %d ms, bench%s asserts %d ms',
                    $key,
                    (int) $found[1],
                    $method,
                    $byMethod[$method],
                );
            }
        }

        self::assertSame(
            [],
            $mismatches,
            'budgets.fpm.json and EndToEndBench have drifted apart: ' . implode('; ', $mismatches),
        );
    }

    /**
     * The claim: covered MSI is enforced at the figure ROADMAP.md names, and no plain-MSI
     * floor exists.
     *
     * ROADMAP.md read "Infection MSI ramp: rc.12 80 (done), rc.13 90", which was wrong
     * twice: no `minMsi` has ever been configured, so 80 was never enforced and cannot
     * have been done, and the 90 listed as future work is the covered-MSI figure already
     * in force. `tools/ci/assert-mutation-thresholds.php` did not catch it because
     * ROADMAP.md is excluded there as a record of past releases — correct for the release
     * history further down the file, and exactly why the forward-looking section rotted.
     */
    #[Test]
    public function theRoadmapNamesTheMutationThresholdThatIsActuallyConfigured(): void
    {
        $config = $this->read($this->repositoryRoot() . DIRECTORY_SEPARATOR . 'infection.json5');

        if (preg_match('/^\s*minCoveredMsi\s*:\s*(\d+)\s*,?\s*$/mi', $config, $covered) !== 1) {
            self::fail('infection.json5 no longer configures a single minCoveredMsi');
        }

        $configured = $covered[1];

        self::assertSame(
            0,
            preg_match('/^\s*minMsi\s*:/mi', $config),
            'infection.json5 now sets a plain minMsi, so ROADMAP.md must stop saying there is no floor',
        );

        $roadmap = $this->read($this->repositoryRoot() . DIRECTORY_SEPARATOR . 'ROADMAP.md');

        self::assertMatchesRegularExpression(
            '/covered\*{0,2} MSI is enforced at ' . $configured . '\b/i',
            $roadmap,
            'ROADMAP.md does not name the covered-MSI figure infection.json5 configures (' . $configured . ')',
        );

        self::assertStringContainsString(
            'no plain-MSI floor to',
            $roadmap,
            'This test exists to back a ROADMAP claim that is no longer made',
        );
    }

    /**
     * The claim: `docs/orm.md` says how aggregates and relation counts treat soft deletes.
     *
     * Both behaviours are deliberate and both are surprising if undocumented: an
     * aggregate that silently counted trashed rows would disagree with the page it
     * paginates, and a relation count that did would print a number beside a shorter
     * list. The page said nothing about either. This holds the documentation and the two
     * implementations together — if the code stops scoping, the page is wrong and this
     * says so.
     */
    #[Test]
    public function ormDocumentsHowAggregatesAndRelationCountsTreatSoftDeletes(): void
    {
        $orm = $this->read($this->repositoryRoot() . DIRECTORY_SEPARATOR . $this->native('docs/orm.md'));

        // Headings, not mentions: a cross-reference to a section that no longer exists
        // would satisfy a substring check while leaving the reader nowhere to go.
        foreach (['Aggregates and soft deletes', 'Relation counts'] as $heading) {
            self::assertMatchesRegularExpression(
                '/^#{2,4} ' . preg_quote($heading, '/') . '\s*$/m',
                $orm,
                'docs/orm.md no longer has a "' . $heading . '" section',
            );
        }

        self::assertStringContainsString(
            'GROUP BY',
            $orm,
            'docs/orm.md no longer says that a grouped query cannot be aggregated',
        );

        $builder = $this->read(
            $this->repositoryRoot() . DIRECTORY_SEPARATOR
            . $this->native('extensions/orm/src/Features/Query/SelectBuilder.php'),
        );

        self::assertSame(
            1,
            preg_match('/public function aggregate\(\).*?compileSoftDeleteFilters\(\)/s', $builder),
            'SelectBuilder::aggregate() no longer applies the soft-delete filter, so docs/orm.md is wrong',
        );

        self::assertStringContainsString(
            'QueryBuilderException::aggregateOverGroupedQuery()',
            $builder,
            'SelectBuilder::aggregate() no longer refuses a grouped query, so docs/orm.md is wrong',
        );

        $counter = $this->read(
            $this->repositoryRoot() . DIRECTORY_SEPARATOR
            . $this->native('extensions/orm/src/Features/Relation/WithCountLoader.php'),
        );

        self::assertStringContainsString(
            'excludeTrashed',
            $counter,
            'WithCountLoader no longer scopes counts to untrashed rows, so docs/orm.md is wrong',
        );
    }

    /**
     * `request.anonymous_json_api` -> `AnonymousJsonApi`.
     */
    private function benchMethodFor(string $budgetKey): string
    {
        $method = '';

        foreach (explode('_', $budgetKey) as $segment) {
            $method .= ucfirst($segment);
        }

        return $method;
    }

    /**
     * Files that could plausibly wire a tool into the build.
     *
     * @return list<string>
     */
    private function wiringSurfaces(): array
    {
        $root = $this->repositoryRoot();
        $workflows = $root . DIRECTORY_SEPARATOR . $this->native('.github/workflows');
        self::assertDirectoryExists($workflows);

        $files = ['composer.json'];

        foreach (['.yml', '.yaml'] as $suffix) {
            foreach ($this->collect($workflows, $suffix) as $path) {
                $files[] = str_replace('\\', '/', substr($path, strlen($root) + 1));
            }
        }

        sort($files);

        self::assertGreaterThan(1, count($files), 'No workflows were found, so this check would pass vacuously');

        return $files;
    }

    /**
     * @return list<string>
     */
    private function markdownUnder(string $directory): array
    {
        return $this->filesUnder($directory, 'md');
    }

    /**
     * Repository-relative forward-slash paths of every file with the extension.
     *
     * @return list<string>
     */
    private function filesUnder(string $directory, string $extension): array
    {
        $root = $this->repositoryRoot();
        self::assertDirectoryExists($root . DIRECTORY_SEPARATOR . $this->native($directory));

        // What git ships, not the disk: a gitignored local page is in no clone.
        $files = TrackedFiles::under($root, '.' . $extension, $directory);
        self::assertNotSame([], $files, 'No .' . $extension . ' files were found under ' . $directory);

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $relative): array
    {
        $decoded = json_decode(
            $this->read($this->repositoryRoot() . DIRECTORY_SEPARATOR . $this->native($relative)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decoded, $relative . ' is not a JSON object');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Remove fenced blocks so an example is never read as a claim.
     */
    private function stripCode(string $markdown): string
    {
        $stripped = preg_replace('/^```.*?^```/ms', '', $markdown);

        return is_string($stripped) ? $stripped : $markdown;
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

    private function native(string $path): string
    {
        return str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, 'Could not read ' . $path);

        return $contents;
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
