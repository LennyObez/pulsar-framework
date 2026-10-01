<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Unit\Integrity\Support\GateCoverageIndex;

use function array_diff;
use function array_keys;
use function array_merge;
use function array_values;
use function dirname;
use function implode;

/**
 * Every integrity ratchet must have been watched refusing.
 *
 * An audit of this repository found nine gates that could not fail — a script that exited
 * before checking anything, a ruleset no workflow ran, a benchmark asserting a budget over
 * zero work, a ratchet whose search skipped the files it was written to catch. Each was
 * discovered separately, and each had been reported as implemented on the strength of the
 * code existing. One proposition covers all nine: a check that has never been observed to
 * fail is indistinguishable from no check.
 *
 * Writing the missing negative tests closes that for the gates that exist today. This is
 * what keeps it closed tomorrow: the list of ratchets is derived from the filesystem, not
 * typed, so a ratchet added next month is in scope the moment it is written, and this
 * fails until somebody has planted its defect and watched it refuse.
 *
 * Two of the rules in scope turned out to be unfalsifiable, and are recorded as such
 * below rather than quietly satisfied. That is the point of the exercise: the honest
 * answer to "can this gate fail" is sometimes no, and no is a finding.
 */
#[CoversClass(GateCoverageIndex::class)]
final class GateNegativeCoverageTest extends TestCase
{
    /**
     * Rules that are deliberately not gates, each with the reason a reviewer can check.
     *
     * Nothing goes in here to make the build green. Every entry states what the rule
     * measures instead, and an entry whose reason has stopped being true is caught by
     * theExemptionsAllNameRulesThatStillExist below.
     *
     * @var array<string, string> rule => why no negative test can or should exist
     */
    private const array EXEMPT = [
        'NoDiscardCorrectnessTest::no_discard_coverage_is_not_empty' =>
            'a vacuity floor, not a rule: it asserts the scan found something to judge, which is '
            . 'the guard against the sibling assertions passing over an empty list. Planting a '
            . 'defect for it would mean deleting every #[NoDiscard] in src/.',
        'OverrideCorrectnessTest::override_coverage_is_not_empty' =>
            'a vacuity floor, as above: it measures that the scan reached the code, not a property '
            . 'of the code.',
        'DocumentedInstallCommandsTest::theScanFindsTheInstallCommandsTheDocumentationActuallyCarries' =>
            'a vacuity floor over THIS checkout: it asserts the scan still finds the `composer require '
            . 'pulsar/framework` that docs/install.md and docs/getting-started.md carry, which is what '
            . 'stops the sibling rule passing over a corpus it stopped reading. Planting its defect '
            . 'means deleting that command from the real documentation. Whether the scan can read a '
            . 'tree it is pointed at is watched separately, by the planted fixture in '
            . 'itReportsAnInstallCommandNamingAPackageThatDoesNotExist -- which is a different claim, '
            . 'and is not allowed to stand in for this one.',
    ];

    #[Test]
    public function everyRatchetRuleHasBeenWatchedRefusing(): void
    {
        $index = $this->index();
        $rules = $index->rules();

        self::assertNotSame(
            [],
            $rules,
            'no ratchets were found at all. This assertion would then be trivially satisfied, '
            . 'which is the exact failure mode it exists to prevent one level down.',
        );

        $covered = array_merge($index->declared(), $index->selfCovering(), array_keys(self::EXEMPT));
        $unguarded = array_values(array_diff($rules, $covered));

        self::assertSame(
            [],
            $unguarded,
            "These gate rules have never been observed failing, so nothing distinguishes them\n"
            . "from a rule that cannot fail — which is what nine gates in this repository turned\n"
            . "out to be, each found separately, each green:\n  "
            . implode("\n  ", $unguarded)
            . "\n\nWrite a test that plants the defect the rule exists to catch, runs the rule, and\n"
            . "asserts it refuses — naming in the assertion message what would have shipped had\n"
            . "the rule stayed silent. Put it in tests/Unit/Integrity/Negative and mark it\n"
            . "#[GuardsGate(gate: 'TheRatchetTest::theRule', plants: '…')].\n"
            . 'If the defect genuinely cannot be planted, say so in EXEMPT with the reason — '
            . 'an unfalsifiable gate is a finding, not a formality.',
        );
    }

    /**
     * An exemption for a rule that no longer exists is the same rot as a stale baseline
     * entry: it grants a permission nobody is using, and it hides that the list was
     * written once and never revisited. Worse here — the next rule to be given that name
     * inherits the exemption silently.
     */
    #[Test]
    public function theExemptionsAllNameRulesThatStillExist(): void
    {
        $stale = array_values(array_diff(array_keys(self::EXEMPT), $this->index()->rules()));

        self::assertSame(
            [],
            $stale,
            "EXEMPT excuses rules that no longer exist. Remove them — otherwise the next rule\n"
            . "written under one of these names is exempt before anyone has read it:\n  "
            . implode("\n  ", $stale),
        );
    }

    private function index(): GateCoverageIndex
    {
        return new GateCoverageIndex(dirname(__DIR__, 3));
    }
}
