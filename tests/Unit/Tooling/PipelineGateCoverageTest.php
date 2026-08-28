<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Unit\Tooling\Support\Gate;
use Pulsar\Tests\Unit\Tooling\Support\GateInventory;
use Pulsar\Tests\Unit\Tooling\Support\PipelineStep;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function sort;
use function sprintf;
use function str_contains;

/**
 * Every gate in the pipeline must have been watched refusing.
 *
 * An audit of this repository found nine gates that could not fail. A script that read a
 * file which does not exist and exited 1 without checking anything. A ruleset whose own
 * header said "NOTHING IN CI RUNS THIS FILE". A benchmark asserting 500 microseconds in a
 * job named "Hard Gate" that executed zero wirings. A ratchet whose `git grep` skipped
 * untracked files, so it was green on a branch it would refuse the moment that branch was
 * committed. Each was found separately, by a different person, on a different day, and
 * every one of them had been reported as implemented on the strength of the code existing.
 *
 * One proposition covers all nine, and it is the proposition this test exists to enforce:
 *
 *     A check that has never been observed to fail is indistinguishable from no check.
 *
 * Writing the missing negative tests closed the nine. This is what keeps them closed. The
 * gate list is DERIVED -- out of composer.json, out of the workflow files, out of the
 * filesystem -- so a gate added next month is in scope the moment it is written, and this
 * test fails until somebody has planted its defect and watched it refuse. A typed list
 * would be the audited defect one level up: complete on the strength of the list existing.
 * See {@see GateInventory} for how each of the four derivations works and what it costs.
 *
 * ## Scope, and the other half of this property
 *
 * This test enumerates the PIPELINE: the leaves of `composer qa`, the first-party scripts
 * the workflows run, the checkers sitting beside them, and the pnpm targets. The
 * repository-reading RATCHETS under tests/Unit/Integrity are enumerated by
 * GateNegativeCoverageTest, which derives its own list the same way and shares the
 * #[GuardsGate] vocabulary. The split is by how a gate is discovered, not by how important
 * it is, and neither enumeration is allowed to claim the other's rows: a declaration
 * naming `Class::method` belongs to the ratchet half and is passed over by
 * {@see everyDeclarationNamesAGateThatExists()}, which states that as an assertion rather
 * than leaving it to be inferred.
 *
 * A split leaves a seam, and this one left a real hole for a while: each enumeration
 * assumed the other was validating the declarations it passed over, so neither did, and a
 * misspelled rule name counted as coverage of nothing in both halves at once.
 * {@see everyMethodLevelDeclarationNamesATestThatExists()} closes it from this side by
 * resolving those names against the actual test methods in the suite.
 *
 * ## What is deliberately NOT claimed
 *
 * That every declaration still plants the RIGHT defect. Nothing short of mutating each
 * gate and re-running proves that, and it is what the workstreams that wrote these tests
 * did by hand, once, recording the red and the green. What
 * {@see everyDeclarationIsBackedByAnObservedRefusal()} does hold is the cheap decay: a
 * negative test whose failing case is deleted and whose passing case survives no longer
 * observes a refusal, and stops satisfying the declaration it makes.
 */
#[CoversClass(GateInventory::class)]
#[CoversClass(Gate::class)]
#[CoversClass(PipelineStep::class)]
final class PipelineGateCoverageTest extends TestCase
{
    /**
     * Gates with no negative test, each with the reason a reviewer can check.
     *
     * NOTHING GOES IN HERE TO MAKE THE BUILD GREEN. Two kinds of entry are legitimate and
     * they are labelled differently on purpose:
     *
     *   UNFALSIFIABLE -- there is no refusal a test could observe, and that is a property
     *   of the gate rather than of anybody's effort. These are permanent and each states
     *   what guards the gate instead.
     *
     *   PENDING -- the negative test can be written and has not been. Each says what
     *   writing it would take, so the entry reads as a work item and not as a dispensation.
     *   The count is capped by EXEMPT_CEILING below, so this list can only shrink without
     *   somebody deliberately raising the cap in a diff a reviewer will see.
     *
     * @var array<string, string> gate => why it carries no negative test
     */
    private const array EXEMPT = [
        'composer test' =>
            'UNFALSIFIABLE: the PHPUnit suite is the process this assertion is running in. A '
            . 'negative test would have to make the suite fail in order to watch it fail, and '
            . 'would then report that as its own failure. Its silence is guarded instead by '
            . 'tools/ci/assert-test-yield.php -- a green run that executed almost nothing is '
            . 'the failure mode that matters -- and that gate has TestYieldGateTest.',
        'pnpm test' =>
            'PENDING: a planted failing Vitest spec driven through a subprocess. The shape is '
            . 'already in the repository -- FrontendGateTest drives eslint, prettier and tsc '
            . 'exactly that way -- so this is unwritten rather than difficult.',
        'composer mutation' =>
            'PENDING: watching minCoveredMsi refuse needs a full Infection run over a planted '
            . 'fixture project, which needs a coverage driver and minutes per assertion. '
            . 'MutationConfigTest asserts the configuration is coherent, which is not the same '
            . 'claim and must not be read as this one.',
        'composer mutation:diff' =>
            'PENDING, as above: the per-change tier differs from the nightly sweep only in '
            . 'scope, so one planted surviving mutant would cover both.',
        'bin/pulsar i18n:slugs:lint' =>
            'PENDING, and the highest-value item on this list: ci.yml records in a comment that '
            . 'an empty localized_slugs config passes as a no-op. That is the vacuous-pass '
            . 'shape, written down and unguarded. Needs a fixture config with a locale missing '
            . 'a slug and a second with a per-locale collision.',
        'bin/pulsar optimize:validate' =>
            'PENDING: needs a fixture application whose warmup cannot produce its artefacts, so '
            . 'the strict validation has something to refuse.',
        'bin/pulsar optimize' =>
            'PENDING: the fallback branch of the same cache-warmup step, and covered by the same '
            . 'fixture once it exists.',
        'bin/pulsar optimize:clear' =>
            'PENDING: the other half of that fallback branch -- a clear that cannot remove what '
            . 'optimize wrote.',
        'bin/pulsar deploy:check' =>
            'PENDING: needs a fixture deployment misconfigured on purpose. Worth deciding first '
            . 'whether this is a gate at all -- no workflow runs it and it is not a leaf of qa, '
            . 'so today it blocks nothing.',
        'scripts/wiring_check.php' =>
            'PENDING: run by no workflow and not a leaf of qa, so the "exists but is wired to '
            . 'nothing" check it performs currently blocks nothing. Promote it to qa or delete '
            . 'it; either way the negative test plants a class nothing references.',
        'scripts/boundary_ratchet.php' =>
            'PENDING: referenced by no composer script and no workflow, so tools/php/'
            . 'boundary-baseline.json can grow freely. The negative test is a baseline that grew '
            . 'by one entry, and it should be written as part of giving this script a caller.',
        'scripts/generate_sbom.php' =>
            'PENDING, and probably a deletion rather than a test: provenance.yml runs '
            . 'tools/sbom/generate-sbom.php instead. A second SBOM generator no gate exercises '
            . 'is a copy that drifts from the one that ships.',
        'tools/sbom/generate-sbom.php' =>
            'PENDING: the generator provenance.yml actually runs. The negative test plants a '
            . 'dependency set it cannot describe and asserts the release refuses rather than '
            . 'publishing an SBOM missing a component.',
        'tools/bench/run.php' =>
            'PENDING: needs a recorded baseline and a profile median 10% below it. '
            . 'BenchmarkProfilesTest asserts the profile matrix is coherent, not that a '
            . 'regression fails, and must not be counted as this.',
        'tools/ci/assert-no-advisories.php' =>
            'PENDING: the script already takes the report path as an argument, so this is the '
            . 'cheapest entry here. Plant an advisory report and an unparseable one -- the '
            . 'second is the defect the script was written to remove, an unreadable report '
            . 'being summed to zero and reported as a clean tree.',
        'tools/version/sync-version.php' =>
            'PENDING: needs one additive --composer= option to point the check at a fixture '
            . 'pair, matching the precedent six other scripts already set. Then plant a '
            . 'src/Core/Version.php constant that disagrees with composer.json.',
        'scripts/check_compliance_claims.php' =>
            'PENDING: needs a booted deployment claiming a control it does not exhibit. This is '
            . 'the script from the original finding -- it used to read a nonexistent file and '
            . 'exit 1 unconditionally -- so it is rewritten but still unwatched.',
        'scripts/qa' =>
            'PENDING: the entrypoint CONTRIBUTING.md points contributors at. It skips all four '
            . 'pnpm gates with a printed warning when node_modules is absent and still exits 0, '
            . 'so a local run reports "All checks passed" having covered none of the JS half. '
            . 'The negative test runs it with node_modules hidden and asserts it refuses.',
    ];

    /**
     * The number of gates allowed to be exempt.
     *
     * A ceiling rather than a count, so removing an exemption does not need this edited,
     * and adding one does. That asymmetry is the point: the honest direction is free and
     * the other direction costs a line in the diff that a reviewer will ask about.
     */
    private const int EXEMPT_CEILING = 18;

    /**
     * Third-party actions that can block a merge from outside this repository.
     *
     * None can carry a negative test: actionlint and zizmor judge the workflows and run
     * only on a GitHub runner, and the SLSA generator needs OIDC id-token minting and a
     * real release event. A local process can produce none of that.
     *
     * Recorded anyway, and asserted, because "cannot be tested" must not quietly become
     * "need not be noticed". A tenth action appearing here is a new thing with authority
     * over the merge, and it should cost somebody a line in a diff.
     *
     * @var list<string>
     */
    private const array EXPECTED_ACTIONS = [
        'actions/cache',
        'actions/checkout',
        'actions/setup-node',
        'actions/upload-artifact',
        'pnpm/action-setup',
        'raven-actions/actionlint',
        'shivammathur/setup-php',
        'slsa-framework/slsa-github-generator/.github/workflows/generator_generic_slsa3.yml',
        'zizmorcore/zizmor-action',
    ];

    /**
     * Steps that carry `continue-on-error: true`, and so cannot fail the build.
     *
     * Both are Tier B nightly benchmarks. That is a defensible design choice -- a nightly
     * report is not a merge gate -- but the job is named "Tier B: Nightly Performance
     * Benchmarks" and reads like one, so the exclusion is written down rather than left to
     * whoever next greps for the budget. If a step ever joins this list, something that
     * used to block stopped blocking, which is exactly the audited finding arriving by a
     * different route.
     *
     * @var list<string>
     */
    private const array TOLERATED_STEPS = [
        'benchmark-nightly.yml "Tier B: Run all benchmarks (broader tolerance)"',
        'benchmark-nightly.yml "Tier B: Run memory peak benchmarks"',
    ];

    #[Test]
    public function everyGateHasBeenWatchedRefusing(): void
    {
        $inventory = $this->inventory();
        $gates = $inventory->gates();

        self::assertNotSame(
            [],
            $gates,
            'the derivation found no gates at all. Every assertion below would then be '
            . 'trivially satisfied, which is the precise failure this test exists to prevent '
            . 'one level down -- a scan that reached nothing being read as a clean result.',
        );

        $declared = array_keys($inventory->declarations());
        $unguarded = [];

        foreach ($gates as $gate) {
            if (isset(self::EXEMPT[$gate->id])) {
                continue;
            }

            foreach ($declared as $declaration) {
                if ($gate->isNamedBy($declaration)) {
                    continue 2;
                }
            }

            $unguarded[] = '  ' . $gate->describe();
        }

        self::assertSame(
            [],
            $unguarded,
            sprintf(
                "These %d gates have never been observed failing, so nothing distinguishes them\n"
                . "from a gate that CANNOT fail -- which is what nine gates in this repository\n"
                . "turned out to be, each found separately, each green the whole time:\n\n%s\n\n"
                . "Write a test that plants the defect the gate exists to catch, runs the real\n"
                . "gate, and asserts it refuses -- naming in the assertion message what would have\n"
                . "shipped had the gate stayed silent. tests/Unit/Tooling/TestYieldGateTest.php is\n"
                . "the worked example, and Support/PlantsDefectsForGates.php has the subprocess\n"
                . "and temp-tree machinery. Then declare it:\n\n"
                . "    #[GuardsGate(gate: '<the name above>', plants: '<the defect>')]\n\n"
                . 'If the defect genuinely cannot be planted, add it to EXEMPT with the reason. '
                . "An\nunfalsifiable gate is a finding, not a formality, and the reason is what a\n"
                . 'reviewer checks instead of the test.',
                count($unguarded),
                implode("\n", $unguarded),
            ),
        );
    }

    /**
     * A declaration is a claim that somebody watched a refusal. This checks there is still
     * a refusal in the file making the claim.
     *
     * The realistic decay is not malice, it is a green diff: a negative test that starts
     * failing for an unrelated reason gets its failing case deleted, the passing case
     * survives, the suite goes green and the declaration keeps standing. After that the
     * gate is exactly as unwatched as it was before anybody wrote the test, with a
     * machine-readable claim that it is not.
     */
    #[Test]
    public function everyDeclarationIsBackedByAnObservedRefusal(): void
    {
        $inventory = $this->inventory();
        $hollow = [];

        foreach ($inventory->declarations() as $gate => $files) {
            foreach ($files as $file) {
                if (GateInventory::observesRefusal((string) file_get_contents($file))) {
                    continue 2;
                }
            }

            $hollow[] = sprintf('  %s — declared by %s', $gate, implode(', ', $files));
        }

        self::assertSame(
            [],
            $hollow,
            "These tests declare themselves the negative test for a gate, and contain no\n"
            . "assertion that watches anything refuse -- no non-zero exit code, no expected\n"
            . "exception, no non-empty finding list:\n\n"
            . implode("\n", $hollow)
            . "\n\nA negative test that only exercises the healthy case distinguishes nothing, and\n"
            . "with the declaration attached it is worse than nothing: the enumeration reports\n"
            . 'the gate as covered. Restore the planted defect, or remove the #[GuardsGate].',
        );
    }

    /**
     * A declaration naming a gate that does not exist is a claim of coverage over nothing.
     *
     * The same rot as a stale baseline entry, with a sharper edge: the next gate to be
     * given that name inherits the coverage claim before anybody has read it. Declarations
     * containing `::` are the ratchet half's vocabulary and belong to
     * GateNegativeCoverageTest, which makes the mirror-image assertion about them.
     */
    #[Test]
    public function everyDeclarationNamesAGateThatExists(): void
    {
        $inventory = $this->inventory();
        $gates = $inventory->gates();

        $pipelineDeclarations = array_values(array_filter(
            array_keys($inventory->declarations()),
            static fn(string $name): bool => !str_contains($name, '::'),
        ));

        self::assertNotSame(
            [],
            $pipelineDeclarations,
            'no pipeline gate declarations were found. Either every #[GuardsGate] has been '
            . 'removed, or the token scan stopped seeing them -- both of which would make the '
            . 'coverage assertion above pass by finding nothing to check.',
        );

        $orphaned = [];

        foreach ($pipelineDeclarations as $declaration) {
            foreach ($gates as $gate) {
                if ($gate->isNamedBy($declaration)) {
                    continue 2;
                }
            }

            $orphaned[] = '  ' . $declaration;
        }

        self::assertSame(
            [],
            $orphaned,
            "These #[GuardsGate] declarations name a gate the derivation cannot find:\n\n"
            . implode("\n", $orphaned)
            . "\n\nEither the gate was removed and the declaration outlived it, or the name is\n"
            . "misspelled, or a ratchet rule was declared without the `Class::method` form that\n"
            . "routes it to GateNegativeCoverageTest. A declaration that resolves to nothing is\n"
            . 'a coverage claim nobody can check.',
        );
    }

    /**
     * A `Class::method` declaration names a test method that exists.
     *
     * Neither enumeration was checking this, and the gap was mutual: this test passes `::`
     * names over as the ratchet half's business, and GateNegativeCoverageTest validates
     * its EXEMPT entries while consuming declarations as coverage without asking whether
     * they resolve. A misspelled rule name was therefore invisible to both at once — the
     * seam two lists always leave when each assumes the other is looking.
     *
     * Resolved against the actual test methods in the suite rather than against one
     * enumeration's rule list. That distinction is not academic: the first version checked
     * only GateCoverageIndex::rules(), which globs tests/Unit/Integrity, and immediately
     * rejected this file's own negative test — a declaration that is perfectly valid and
     * simply points at a gate living somewhere else. A check that reports a correct
     * declaration as broken teaches people to route around it.
     */
    #[Test]
    public function everyMethodLevelDeclarationNamesATestThatExists(): void
    {
        $inventory = $this->inventory();
        $methods = $inventory->testMethodsByClass();

        self::assertNotSame(
            [],
            $methods,
            'no test methods were found anywhere under tests/, so this check would pass over '
            . 'an empty list — the vacuity it exists one level up to prevent.',
        );

        $orphaned = [];

        foreach (array_keys($inventory->declarations()) as $declaration) {
            if (!str_contains($declaration, '::')) {
                continue;
            }

            [$class, $method] = explode('::', $declaration, 2);

            if (!in_array($method, $methods[$class] ?? [], true)) {
                $orphaned[] = '  ' . $declaration;
            }
        }

        self::assertSame(
            [],
            $orphaned,
            "These #[GuardsGate] declarations name a test method that does not exist:\n\n"
            . implode("\n", $orphaned)
            . "\n\nThe rule was renamed, or the name is misspelled. Either way the negative test\n"
            . "carrying it guards nothing, while the enumeration that owns the rule it meant to\n"
            . 'name still counts that rule as unguarded — or, worse, counts another as guarded.',
        );
    }

    /**
     * An exemption for a gate that no longer exists grants a dispensation nobody is using
     * and hides that the list was written once and never revisited.
     */
    #[Test]
    public function theExemptionsAllNameGatesThatStillExist(): void
    {
        $gates = $this->inventory()->gates();

        $stale = array_values(array_filter(
            array_keys(self::EXEMPT),
            static function (string $name) use ($gates): bool {
                foreach ($gates as $gate) {
                    if ($gate->id === $name) {
                        return false;
                    }
                }

                return true;
            },
        ));

        self::assertSame(
            [],
            $stale,
            "EXEMPT excuses gates that the derivation no longer finds:\n  "
            . implode("\n  ", $stale)
            . "\n\nRemove them. Otherwise the next gate written under one of these names is exempt\n"
            . 'before anybody has read it -- which is how a list stops describing the thing it names.',
        );
    }

    /**
     * The exemption list may shrink freely and may not grow quietly.
     */
    #[Test]
    public function theExemptionListHasNotGrown(): void
    {
        self::assertLessThanOrEqual(
            self::EXEMPT_CEILING,
            count(self::EXEMPT),
            sprintf(
                "EXEMPT now holds %d entries against a ceiling of %d.\n\n"
                . "Adding an exemption is allowed and is meant to cost something: raise\n"
                . "EXEMPT_CEILING in the same commit, so that a reviewer is asked to agree that\n"
                . "this gate really cannot be watched refusing. A gate list that grows while the\n"
                . 'covered set does not is the audited finding accumulating in slow motion.',
                count(self::EXEMPT),
                self::EXEMPT_CEILING,
            ),
        );
    }

    /**
     * Nothing has quietly stopped being able to fail.
     */
    #[Test]
    public function onlyTheRecordedStepsAreAllowedToFailWithoutBlocking(): void
    {
        $tolerated = array_map(
            static fn(PipelineStep $step): string => sprintf('%s "%s"', $step->workflow, $step->name),
            $this->inventory()->toleratedSteps(),
        );

        sort($tolerated);
        $expected = self::TOLERATED_STEPS;
        sort($expected);

        self::assertSame(
            $expected,
            $tolerated,
            "The set of workflow steps carrying `continue-on-error: true` has changed.\n\n"
            . "A step that gains it stops being able to fail the build while keeping the name\n"
            . "that says it can -- which is the audited finding exactly, arriving as a one-line\n"
            . "YAML edit rather than as a broken script. If the change is deliberate, update\n"
            . "TOLERATED_STEPS and say why in the commit; if a gate is in this list, it is a\n"
            . 'report now and its negative test is measuring something that no longer blocks.',
        );
    }

    /**
     * The set of third-party actions with authority over this repository is unchanged.
     */
    #[Test]
    public function noNewThirdPartyActionHasGainedAuthorityOverTheMerge(): void
    {
        $actions = $this->inventory()->thirdPartyActions();
        $expected = self::EXPECTED_ACTIONS;
        sort($expected);

        self::assertSame(
            $expected,
            $actions,
            sprintf(
                "The third-party actions used by .github/workflows have changed.\n\n"
                . "Added: %s\nRemoved: %s\n\n"
                . "These are the gates this enumeration cannot reach -- they need a GitHub runner,\n"
                . "or OIDC token minting, or a real release event -- so none of them can carry a\n"
                . "negative test. That is precisely why the set is pinned: an action that can block\n"
                . "a merge and cannot be exercised locally is a blind spot, and a blind spot is\n"
                . 'allowed to exist but not to widen without anybody noticing.',
                implode(', ', array_diff($actions, $expected)) ?: '(none)',
                implode(', ', array_diff($expected, $actions)) ?: '(none)',
            ),
        );
    }

    private function inventory(): GateInventory
    {
        return new GateInventory(dirname(__DIR__, 3));
    }
}
