<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tooling\Support\JsonDocument;

use function array_diff;
use function array_filter;
use function array_values;
use function dirname;
use function escapeshellarg;
use function explode;
use function implode;
use function shell_exec;
use function sort;
use function str_starts_with;
use function trim;

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

        $output = (string) shell_exec(
            'git -C ' . escapeshellarg($root)
            . ' grep -lE "\\bDriver::(MySQL|PostgreSQL|SQLite)\\b" -- "src/*.php" "extensions/*.php" 2>&1',
        );

        $files = array_values(array_filter(
            explode("\n", $output),
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
