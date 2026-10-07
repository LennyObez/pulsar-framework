<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Negative;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Tests\Support\FilesystemTestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Integrity\Support\DriverDispatchScanner;
use Pulsar\Tests\Unit\Integrity\Support\PlantsFiles;

use function count;
use function implode;

/**
 * The engine-dispatch ratchet, watched refusing.
 *
 * This is the ratchet whose defect was found by reading a flag list. `git grep` without
 * `--untracked` searches only files git already tracks, so a new `src/Compliance` class
 * dispatching on three `Driver` cases was invisible for as long as it stayed uncommitted:
 * green on the branch, red in CI on the commit that made it tracked. Nothing detected
 * that, because the scan could only ever be pointed at this checkout and this checkout is
 * clean — the ratchet's silence and the ratchet's correctness produced the same output.
 *
 * So the fixture below plants the new dispatch site and never commits it. Deleting
 * `--untracked` from the scanner turns `itSeesADispatchSiteThatIsNotCommittedYet` red,
 * which is the property that could not exist while the root was hard-wired.
 */
#[CoversClass(DriverDispatchScanner::class)]
#[GuardsGate(
    gate: 'DriverDispatchRatchetTest::noNewCodeDecidesBehaviourByNamingAnEngine',
    plants: 'a fixture repository with two engine-dispatch sites the baseline does not list, one of them uncommitted',
)]
#[GuardsGate(
    gate: 'DriverDispatchRatchetTest::theBaselineHasNoStaleEntries',
    plants: 'a baseline naming a file that dispatches on no engine',
)]
#[GuardsGate(
    gate: 'DriverDispatchRatchetTest::theBaselineStatesTheNumberItLists',
    plants: 'a baseline whose declared count is not the length of its files list',
)]
final class DriverDispatchRefusesTest extends FilesystemTestCase
{
    use PlantsFiles;

    /** What a dispatch site looks like: behaviour selected by naming the engine. */
    private const string DISPATCHES = <<<'PHP'
        <?php

        declare(strict_types=1);

        final class Whatever
        {
            public function quote(Driver $driver): string
            {
                return match ($driver) {
                    Driver::MySQL => '`',
                    Driver::PostgreSQL, Driver::SQLite => '"',
                };
            }
        }
        PHP;

    /** The same class, asking the dialect instead of the engine. */
    private const string DOES_NOT_DISPATCH = <<<'PHP'
        <?php

        declare(strict_types=1);

        final class Whatever
        {
            public function quote(Dialect $dialect): string
            {
                return $dialect->identifierQuote();
            }
        }
        PHP;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Debt the baseline already records, so the ratchet has something to be
        // legitimately silent about and "reports everything" cannot pass for "refuses".
        $this->plant($this->tempDirectory, 'src/Legacy/KnownDebt.php', self::DISPATCHES);

        // Dispatch inside the layer whose job is to know the engines: by design, and
        // excluded. If this turned up in the answer the ratchet would be unusable.
        $this->plant($this->tempDirectory, 'src/Database/MySqlDialect.php', self::DISPATCHES);

        // A test naming an engine is the test's whole purpose, and an extension's
        // vendored dependency is not ours to shape. Both are excluded by path.
        $this->plant($this->tempDirectory, 'extensions/thing/tests/DialectTest.php', self::DISPATCHES);
        $this->plant($this->tempDirectory, 'extensions/thing/vendor/acme/orm/Grammar.php', self::DISPATCHES);

        // Ordinary code that asks the dialect, which is what the ratchet is asking for.
        $this->plant($this->tempDirectory, 'src/Reporting/Exporter.php', self::DOES_NOT_DISPATCH);
    }

    /**
     * The defect the ratchet exists for: a dispatch site the baseline does not list.
     */
    #[Test]
    public function itRefusesADispatchSiteTheBaselineDoesNotList(): void
    {
        $this->plant($this->tempDirectory, 'src/Compliance/RetentionSweeper.php', self::DISPATCHES);

        $unexpected = DriverDispatchScanner::unexpected(
            $this->sitesInFixture(),
            ['src/Legacy/KnownDebt.php'],
        );

        self::assertContains(
            'src/Compliance/RetentionSweeper.php',
            $unexpected,
            'The engine-dispatch ratchet stayed silent on a new file selecting behaviour by '
            . 'naming a Driver case. What ships when it stays silent is a codebase where '
            . 'adding a database engine is a breaking change: every one of these matches '
            . 'throws UnhandledMatchError the first time it meets a case that did not exist '
            . 'when it was written, and the list the ratchet exists to shrink grows instead. '
            . 'It reported: [' . implode(', ', $unexpected) . ']',
        );
    }

    /**
     * The `--untracked` repair, locked.
     *
     * Without the flag this file is invisible to git grep until it is committed, so the
     * ratchet reports green to the only person still in a position to fix it and fails
     * for whoever merges. Reverting the flag fails here instead.
     */
    #[Test]
    public function itSeesADispatchSiteThatIsNotCommittedYet(): void
    {
        $this->initialiseFixtureRepository();

        // Planted AFTER staging, so git tracks it in no sense at all — exactly the state
        // a new file is in on the branch where it was written.
        $this->plant($this->tempDirectory, 'src/Compliance/RetentionSweeper.php', self::DISPATCHES);

        $sites = $this->scanFixture();

        self::assertContains(
            'src/Compliance/RetentionSweeper.php',
            $sites,
            'The ratchet did not see an uncommitted dispatch site, which is the state every '
            . 'new file is in while it is being written. What ships when it stays silent is '
            . 'a ratchet that is green for the author and red for the merge — it reports the '
            . 'debt to the one person who can no longer act on it. This is the audited '
            . 'defect: `git grep` without `--untracked`. It reported: ['
            . implode(', ', $sites) . ']',
        );
    }

    /**
     * The exclusions, asserted so the refusals above cannot be "it reports everything".
     */
    #[Test]
    public function itIsSilentOnDispatchThatIsNotDebt(): void
    {
        $sites = $this->sitesInFixture();

        self::assertNotContains('src/Database/MySqlDialect.php', $sites, 'the dispatch layer is the design');
        self::assertNotContains('extensions/thing/tests/DialectTest.php', $sites, 'a test may name an engine');
        self::assertNotContains('extensions/thing/vendor/acme/orm/Grammar.php', $sites, 'vendored code is not ours');
        self::assertNotContains('src/Reporting/Exporter.php', $sites, 'this file asks the dialect');
        self::assertContains('src/Legacy/KnownDebt.php', $sites, 'the search found nothing at all');
    }

    #[Test]
    public function itRefusesABaselineEntryThatDispatchesOnNothing(): void
    {
        $stale = DriverDispatchScanner::stale(
            $this->sitesInFixture(),
            ['src/Legacy/KnownDebt.php', 'src/Ancient/DeletedAgesAgo.php'],
        );

        self::assertContains(
            'src/Ancient/DeletedAgesAgo.php',
            $stale,
            'The ratchet stayed silent on a baseline entry no file answers to. What ships '
            . 'when it stays silent is a remaining-debt figure that overstates itself and a '
            . 'standing permission for a path anyone can recreate — the list stops being the '
            . 'honest measure the whole exercise is driving down. It reported: ['
            . implode(', ', $stale) . ']',
        );
    }

    #[Test]
    public function itRefusesABaselineWhoseCountIsNotItsLength(): void
    {
        $baseline = $this->plant($this->tempDirectory, 'baseline.json', <<<'JSON'
            {
              "count": 80,
              "files": ["src/Legacy/KnownDebt.php", "src/Ancient/DeletedAgesAgo.php"]
            }
            JSON);

        $listed = count(DriverDispatchScanner::baselineSites($baseline));
        $declared = DriverDispatchScanner::declaredCount($baseline);

        self::assertNotSame(
            $listed,
            $declared,
            'The baseline declared a count equal to its list, so this fixture proves nothing.',
        );
        self::assertSame(80, $declared);
        self::assertSame(2, $listed);
    }

    /**
     * The healthy baseline, so the mismatch above is a property of the fixture and not of
     * the reader.
     */
    #[Test]
    public function itIsSilentOnABaselineThatCountsItself(): void
    {
        $baseline = $this->plant($this->tempDirectory, 'honest.json', <<<'JSON'
            {
              "count": 2,
              "files": ["src/Legacy/KnownDebt.php", "src/Ancient/DeletedAgesAgo.php"]
            }
            JSON);

        self::assertSame(
            count(DriverDispatchScanner::baselineSites($baseline)),
            DriverDispatchScanner::declaredCount($baseline),
        );
    }

    /**
     * @return list<string>
     */
    private function sitesInFixture(): array
    {
        $this->initialiseFixtureRepository();

        return $this->scanFixture();
    }

    private function initialiseFixtureRepository(): void
    {
        if (!$this->initialiseRepository($this->tempDirectory)) {
            self::markTestSkipped('git cannot be driven here, so the fixture repository cannot be built');
        }
    }

    /**
     * @return list<string>
     */
    private function scanFixture(): array
    {
        $sites = new DriverDispatchScanner($this->tempDirectory)->currentSites();

        self::assertNotNull($sites, 'the fixture repository was built but git would not search it');

        return $sites;
    }
}
