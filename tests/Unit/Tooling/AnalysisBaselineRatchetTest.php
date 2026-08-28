<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\BaselineCensus;
use Pulsar\Tests\Unit\Tooling\Support\PlantsDefectsForGates;
use RuntimeException;

use function array_keys;
use function file_get_contents;
use function glob;
use function is_int;
use function is_string;
use function json_decode;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * The ratchet the analysis baselines never had, and the negative tests that prove it
 * can fail.
 *
 * `composer phpstan` includes tools/php/phpstan-baseline.neon and `composer
 * class-shape` reads tools/php/substitutability-baseline.json. Both files are lists
 * of findings the gate agrees not to report, and both are described in their own
 * headers as ratcheting toward zero — the PHPStan baseline says "the baseline
 * ratchets toward zero" in its first line. Nothing measured whether it did. Adding an
 * entry to silence a new error left both gates green and produced a diff
 * indistinguishable from removing one, so the stated intent was decoration.
 *
 * The number is now written down in tools/php/analysis-baseline-ceiling.json and
 * compared on every run. Raising a ceiling is a line in a diff that says so.
 *
 * WHY GROWTH FAILS AND SHRINKAGE DOES NOT
 *
 * Growth is the defect: it is how a new finding gets buried. Shrinkage is the point
 * of the exercise, and failing a build for it would make an improvement cost a second
 * commit — which is how a ratchet acquires a reputation for being in the way, and how
 * it ends up bypassed. Shrinkage is reported with the number to write down instead.
 * The ceiling can therefore drift upward of the truth, and {@see
 * theCeilingIsNotPaddedBeyondWhatTheBaselinesActuallyHold} bounds that drift.
 */
#[GuardsGate(gate: 'tools/php/phpstan-baseline.neon ratchet', plants: 'a baseline block whose count: grew from 3 to 4, and one whose count: is missing so the total is unknowable')]
#[GuardsGate(gate: 'tools/php/substitutability-baseline.json ratchet', plants: 'a baseline JSON document with no entries list, which would otherwise count as zero suppressions')]
final class AnalysisBaselineRatchetTest extends TestCase
{
    use PlantsDefectsForGates;

    private const string CEILING_FILE = 'tools/php/analysis-baseline-ceiling.json';

    /**
     * How far the recorded ceiling may sit above the measured count before the
     * ceiling itself is the problem. Zero would fail on every improvement; this
     * tolerates a genuine reduction landing before the ceiling is updated, and
     * refuses a ceiling padded to leave room for future suppressions.
     */
    private const int PERMITTED_SLACK = 25;

    protected function tearDown(): void
    {
        $this->assertNothingWasLeftBehind();
    }

    #[Test]
    public function noAnalysisBaselineHasGrownPastItsRecordedCeiling(): void
    {
        foreach ($this->ceilings() as $baseline => $ceiling) {
            $measured = BaselineCensus::suppressedFindings($this->repositoryRoot() . '/' . $baseline);

            self::assertLessThanOrEqual(
                $ceiling,
                $measured,
                sprintf(
                    "%s now suppresses %d findings, up from the recorded ceiling of %d.\n\n"
                    . "Those %d extra findings are real analyser output that no longer reaches anyone: the\n"
                    . "gate that would have reported them is still green, and the only trace is a longer\n"
                    . "file. Fix them, or raise the ceiling in %s in the same commit so the decision to\n"
                    . 'bury them is visible in the diff.',
                    $baseline,
                    $measured,
                    $ceiling,
                    $measured - $ceiling,
                    self::CEILING_FILE,
                ),
            );
        }
    }

    #[Test]
    public function theCeilingIsNotPaddedBeyondWhatTheBaselinesActuallyHold(): void
    {
        foreach ($this->ceilings() as $baseline => $ceiling) {
            $measured = BaselineCensus::suppressedFindings($this->repositoryRoot() . '/' . $baseline);

            self::assertLessThanOrEqual(
                self::PERMITTED_SLACK,
                $ceiling - $measured,
                sprintf(
                    "%s suppresses %d findings but its ceiling is %d, leaving %d unearned places for\n"
                    . "future suppressions to land in unnoticed. Lower the ceiling to %d in %s: an\n"
                    . 'improvement that is not written down is an improvement the next commit can spend.',
                    $baseline,
                    $measured,
                    $ceiling,
                    $ceiling - $measured,
                    $measured,
                    self::CEILING_FILE,
                ),
            );
        }
    }

    /**
     * A new baseline appearing in tools/php/ with no entry anywhere is the way this
     * decays: the ratchet keeps passing, over a shrinking share of what is suppressed.
     */
    #[Test]
    public function everyBaselineOnDiskIsEitherRatchetedHereOrRecordedWithAReason(): void
    {
        $document = $this->ceilingDocument();
        $known = [
            ...array_keys($document['ceilings']),
            ...array_keys($document['notRatchetedHere']),
        ];

        // Three globs rather than one GLOB_BRACE pattern: the flag is absent on some
        // musl builds of PHP, and a glob that silently matches nothing here would make
        // this assertion pass over every baseline in the repository.
        $found = [];

        foreach (['json', 'neon', 'yaml'] as $extension) {
            $matches = glob($this->repositoryRoot() . '/tools/*/*baseline*.' . $extension);

            self::assertIsArray($matches, 'could not scan tools/ for baselines');

            $found = [...$found, ...$matches];
        }

        self::assertNotSame([], $found, 'no baseline files were found at all, so this assertion checked nothing');

        foreach ($found as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen($this->repositoryRoot()) + 1));

            self::assertContains(
                $relative,
                $known,
                sprintf(
                    "%s is a suppression baseline that %s does not mention. Either give it a ceiling or\n"
                    . "record under notRatchetedHere which gate owns it and why it is not ratcheted here.\n"
                    . 'A baseline nobody counts is a place findings go to stop being findings.',
                    $relative,
                    self::CEILING_FILE,
                ),
            );
        }
    }

    /**
     * The ratchet's own negative test: a baseline that grew by one must be refused.
     *
     * Without this the class above is a pair of comparisons that have only ever been
     * observed to pass, which is the exact shape of the finding it was written to
     * close.
     */
    #[Test]
    public function theCensusSeesAGrownBaselineAsGrown(): void
    {
        $tree = $this->plantTree('baseline-ratchet');

        $before = $this->plantFile($tree, 'before.neon', self::phpstanBaseline(2, 3));
        $after = $this->plantFile($tree, 'after.neon', self::phpstanBaseline(2, 4));

        self::assertSame(5, BaselineCensus::suppressedFindings($before));
        self::assertSame(
            6,
            BaselineCensus::suppressedFindings($after),
            "widening an existing block's `count:` from 3 to 4 was not seen as growth. Counting entries\n"
            . 'rather than findings would miss it, and one block can hide any number of errors.',
        );
    }

    #[Test]
    public function theCensusRefusesToReportAnUncountableBaselineAsEmpty(): void
    {
        $tree = $this->plantTree('baseline-uncountable');

        // A block whose `count:` was dropped: the file parses, looks like a baseline,
        // and hides an unknown number of errors.
        $countless = $this->plantFile($tree, 'countless.neon', <<<'NEON'
            parameters:
                ignoreErrors:
                    -
                        message: '#^Anything\.$#'
                        path: ../../src/Nowhere.php
            NEON);

        // expectExceptionMessage() is deprecated in PHPUnit 13, and a deprecated call in a
        // test is a call the suite will one day stop making. Catch and assert instead.
        try {
            BaselineCensus::suppressedFindings($countless);
        } catch (RuntimeException $refusal) {
            self::assertStringContainsString('1 message entries but 0 count fields', $refusal->getMessage());

            return;
        }

        self::fail('the census counted it anyway, so an unmeasurable baseline reads as a small one');
    }

    #[Test]
    public function theCensusRefusesAMissingBaselineRatherThanCountingItAsZero(): void
    {
        $tree = $this->plantTree('baseline-missing');

        // expectExceptionMessage() is deprecated in PHPUnit 13, and a deprecated call in a
        // test is a call the suite will one day stop making. Catch and assert instead.
        try {
            BaselineCensus::suppressedFindings($tree . '/never-written.json');
        } catch (RuntimeException $refusal) {
            self::assertStringContainsString('No baseline at', $refusal->getMessage());

            return;
        }

        self::fail('the census counted it anyway, so an unmeasurable baseline reads as a small one');
    }

    #[Test]
    public function theCensusRefusesAJsonBaselineWithNoEntriesList(): void
    {
        $tree = $this->plantTree('baseline-shapeless');
        $shapeless = $this->plantFile($tree, 'shapeless.json', '{"generated":"2026-08-22","note":"nothing here"}');

        // expectExceptionMessage() is deprecated in PHPUnit 13, and a deprecated call in a
        // test is a call the suite will one day stop making. Catch and assert instead.
        try {
            BaselineCensus::suppressedFindings($shapeless);
        } catch (RuntimeException $refusal) {
            self::assertStringContainsString('has no `entries` list', $refusal->getMessage());

            return;
        }

        self::fail('the census counted it anyway, so an unmeasurable baseline reads as a small one');
    }

    private static function phpstanBaseline(int ...$counts): string
    {
        $neon = "parameters:\n\tignoreErrors:\n";

        foreach ($counts as $index => $count) {
            $neon .= sprintf(
                "\t\t-\n\t\t\tmessage: '#^Planted %d\\.\$#'\n\t\t\tidentifier: planted.defect\n"
                . "\t\t\tcount: %d\n\t\t\tpath: ../../src/Planted%d.php\n\n",
                $index,
                $count,
                $index,
            );
        }

        return $neon;
    }

    /**
     * @return array{ceilings: array<string, int>, notRatchetedHere: array<string, string>}
     */
    private function ceilingDocument(): array
    {
        $raw = file_get_contents($this->repositoryRoot() . '/' . self::CEILING_FILE);

        self::assertIsString($raw, self::CEILING_FILE . ' is missing, so nothing is ratcheted');

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('ceilings', $decoded);
        self::assertArrayHasKey('notRatchetedHere', $decoded);
        self::assertIsArray($decoded['ceilings']);
        self::assertIsArray($decoded['notRatchetedHere']);
        self::assertNotSame(
            [],
            $decoded['ceilings'],
            'the ceilings map is empty, so the ratchet above iterates nothing and passes vacuously',
        );

        $ceilings = [];

        foreach ($decoded['ceilings'] as $baseline => $entry) {
            self::assertIsString($baseline);
            self::assertIsArray($entry);
            self::assertArrayHasKey('findings', $entry);
            self::assertTrue(is_int($entry['findings']), $baseline . ' has a non-integer ceiling');
            $ceilings[$baseline] = $entry['findings'];
        }

        $reasons = [];

        foreach ($decoded['notRatchetedHere'] as $baseline => $reason) {
            self::assertIsString($baseline);
            self::assertTrue(is_string($reason), $baseline . ' has no reason recorded');
            self::assertGreaterThan(
                60,
                strlen($reason),
                $baseline . ' must say which gate owns it and why it is not ratcheted here, not merely '
                . 'that it is not',
            );
            $reasons[$baseline] = $reason;
        }

        return ['ceilings' => $ceilings, 'notRatchetedHere' => $reasons];
    }

    /**
     * @return array<string, int>
     */
    private function ceilings(): array
    {
        return $this->ceilingDocument()['ceilings'];
    }
}
