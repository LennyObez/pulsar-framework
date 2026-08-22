<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tooling\Support\JsonDocument;

use function array_diff;
use function array_filter;
use function array_map;
use function array_values;
use function dirname;
use function escapeshellarg;
use function exec;
use function implode;
use function sort;
use function str_contains;
use function str_starts_with;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * Code that decides behaviour by naming a database engine may only shrink.
 *
 * `Driver` answers one question — which PDO driver opens this connection. It is an
 * identity. The moment a caller writes `match ($driver)` it has taken on a second job:
 * knowing every engine the framework will ever support. That is a job the framework can
 * keep up with and a caller cannot, and it is what makes adding an engine a breaking
 * change rather than an additive one — an exhaustive match throws `UnhandledMatchError`
 * the first time it meets a case that did not exist when it was written.
 *
 * Inside `src/Database` the dispatch is legitimate: that layer IS the thing that knows
 * the engines, and the analysers enforce exhaustiveness there. Everywhere else it is
 * debt, and the debt is large — a first-party extension currently selects between twelve
 * hand-written upsert statements, four tables by three engines, where the framework's own
 * portable upsert builder would need one call.
 *
 * ## Why a ratchet and not a ban
 *
 * A ban would fail on the eighty-six files that already do it, so it would be switched
 * off within the day. A count would let one site be added while another is removed. The
 * baseline names every file, so removing one is visible in the diff and adding one fails
 * the build — the debt can only go down, and the direction is enforced rather than
 * intended.
 *
 * Tests are deliberately out of scope. A test that constructs `Driver::MySQL` to check
 * what the MySQL dialect emits must name the engine; that is the test's whole purpose.
 */
final class DriverDispatchRatchetTest extends TestCase
{
    private const string BASELINE = __DIR__ . '/driver-dispatch-baseline.json';

    /** Where a shell sends output it should not keep. */
    private const string NULL_DEVICE = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

    /**
     * The layer whose job is to know the engines. Dispatch here is the design.
     */
    private const string DISPATCH_LAYER = 'src/Database/';

    #[Test]
    public function noNewCodeDecidesBehaviourByNamingAnEngine(): void
    {
        $unexpected = array_values(array_diff($this->currentSites(), $this->baselineSites()));

        self::assertSame(
            [],
            $unexpected,
            'These files name a Driver case to decide behaviour, and they are not in the '
            . "baseline:\n  " . implode("\n  ", $unexpected)
            . "\n\nAsk the dialect for what you need instead of asking which engine it is — "
            . 'quoting, limits, upserts and RETURNING support are all answerable without '
            . "naming an engine.\n"
            . 'If the dispatch genuinely belongs in ' . self::DISPATCH_LAYER . ', move it '
            . 'there. Adding an entry to the baseline is not a fix: the list exists to shrink.',
        );
    }

    /**
     * The baseline must not outlive what it records.
     *
     * A stale entry grants permission nobody is using and hides that the list was never
     * revisited — and here it would also overstate the remaining debt, which is the
     * number this whole exercise is trying to drive down.
     */
    #[Test]
    public function theBaselineHasNoStaleEntries(): void
    {
        $stale = array_values(array_diff($this->baselineSites(), $this->currentSites()));

        self::assertSame(
            [],
            $stale,
            'The baseline lists files that no longer name a Driver case. Remove them — '
            . "the count is the remaining debt and must stay honest:\n  "
            . implode("\n  ", $stale),
        );
    }

    /**
     * @return list<string>
     */
    private function baselineSites(): array
    {
        $files = JsonDocument::fromFile(self::BASELINE)->stringList('files');
        sort($files);

        return $files;
    }

    /**
     * Every production file outside the dispatch layer that names a Driver case.
     *
     * git is asked which files are tracked rather than the directory being walked: a
     * scratch file or a vendored copy appearing during ordinary work must not fire a
     * guard, or the guard earns being switched off.
     *
     * @return list<string>
     */
    private function currentSites(): array
    {
        $root = dirname(__DIR__, 3);

        exec(
            'git -C ' . escapeshellarg($root)
            . ' grep -lE "\\bDriver::(MySQL|PostgreSQL|SQLite)\\b" -- "src/*.php" "extensions/*.php"'
            . ' 2>' . self::NULL_DEVICE,
            $output,
            $status,
        );

        // `git grep -l` exits 0 when it matched and 1 when it did not. Anything above that
        // means git could not answer at all — no repository in this checkout, or no git
        // installed — which is not the same as a clean tree and must not read as one.
        //
        // Stderr goes to the null device rather than into this list. Merged with `2>&1` it
        // arrived as data: `fatal: not a git repository` became a filename, the guard below
        // saw a non-empty list and passed, and the ratchet reported that file as new debt.
        if ($status > 1) {
            self::markTestSkipped(
                'git cannot read this checkout, so the dispatch sites cannot be enumerated',
            );
        }

        $files = array_values(array_filter(
            $output,
            static fn(string $line): bool => $line !== ''
                && !str_starts_with($line, self::DISPATCH_LAYER)
                && !str_contains($line, '/tests/'),
        ));

        $files = array_map(trim(...), $files);
        sort($files);

        self::assertNotSame([], $files, 'no dispatch sites found at all — the search is wrong, not the code');

        return $files;
    }
}
