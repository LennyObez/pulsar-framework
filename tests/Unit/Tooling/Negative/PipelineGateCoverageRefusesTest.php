<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Negative;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\PipelineGateCoverageTest;
use Pulsar\Tests\Unit\Tooling\Support\Gate;
use Pulsar\Tests\Unit\Tooling\Support\GateInventory;
use Pulsar\Tests\Unit\Tooling\Support\PlantsDefectsForGates;

use function array_keys;
use function array_map;
use function implode;
use function in_array;

/**
 * The enumeration is itself a gate, so it gets the treatment it hands out.
 *
 * PipelineGateCoverageTest asserts that every gate has been watched refusing. That claim
 * is worth exactly what its own correctness is worth, and it fails in the direction the
 * whole finding is about: a derivation that quietly stops finding gates reports a fully
 * covered repository. Nobody would notice. It would be green.
 *
 * So each of the enumeration's load-bearing judgements is exercised against a planted
 * defect here. The fixtures are miniature repositories in the system temp root -- outside
 * every configured scan path, so no gate that runs over this checkout can reach them --
 * and each one is built to be wrong in exactly one way.
 */
#[GuardsGate(
    gate: 'PipelineGateCoverageTest::everyGateHasBeenWatchedRefusing',
    plants: 'a fixture repository whose composer qa leaf runs a checker no #[GuardsGate] names, a declaration made only in prose, a negative test with its refusal deleted, and a gate hidden behind continue-on-error',
)]
#[CoversClass(GateInventory::class)]
#[CoversClass(Gate::class)]
final class PipelineGateCoverageRefusesTest extends TestCase
{
    use PlantsDefectsForGates;

    protected function tearDown(): void
    {
        $this->assertNothingWasLeftBehind();

        parent::tearDown();
    }

    /**
     * The headline property: a gate nothing guards is reported as unguarded.
     *
     * Without this, the enumeration's green could mean "every gate is covered" or "the
     * derivation found no gates", and those are the two things it exists to tell apart.
     */
    #[Test]
    public function itReportsAGateThatNoNegativeTestNames(): void
    {
        $tree = $this->plantRepository();

        $this->plantFile($tree, 'tools/ci/assert-nobody-watches-this.php', <<<'PHP'
            <?php
            // A gate added the way gates actually get added: a script, a composer entry,
            // a CI step. Nothing declares a negative test for it.
            exit(1);
            PHP);

        $gates = new GateInventory($tree)->gates();
        $names = array_map(static fn(Gate $gate): string => $gate->id, $gates);

        self::assertContains(
            'tools/ci/assert-nobody-watches-this.php',
            $names,
            'the derivation did not find a checker that composer qa runs. A gate the '
            . 'enumeration cannot see is a gate the enumeration reports as covered, which is '
            . 'the audited finding relocated into the tool built to detect it. Found: '
            . implode(', ', $names),
        );

        $declared = array_keys(new GateInventory($tree)->declarations());

        foreach ($gates as $gate) {
            if ($gate->id !== 'tools/ci/assert-nobody-watches-this.php') {
                continue;
            }

            foreach ($declared as $declaration) {
                self::assertFalse(
                    $gate->isNamedBy($declaration),
                    'a gate nothing guards was matched to a declaration; the coverage check '
                    . 'would pass over it.',
                );
            }
        }
    }

    /**
     * Prose naming the attribute is not the attribute.
     *
     * GateCoverageIndex was built with a text scan and its own docblock -- which names
     * #[GuardsGate] in a sentence -- registered as a declaration. Two gates read as covered
     * because two comments mentioned them. The same trap is one careless edit away here,
     * and it is the trap QaCiParityTest was written for one level up, so it gets an
     * assertion rather than a note.
     */
    #[Test]
    public function itDoesNotCountAMentionOfTheAttributeAsADeclaration(): void
    {
        $tree = $this->plantRepository();

        $this->plantFile($tree, 'tests/PretendsToGuardTest.php', <<<'PHP'
            <?php
            /**
             * This class is #[GuardsGate(gate: 'tools/ci/assert-nobody-watches-this.php')]
             * in spirit, which is to say not at all.
             */
            final class PretendsToGuardTest
            {
                private const string CLAIM = "#[GuardsGate(gate: 'composer phpstan')]";
            }
            PHP);

        $declared = array_keys(new GateInventory($tree)->declarations());

        self::assertSame(
            [],
            $declared,
            'a docblock and a string literal were read as declarations of coverage. A gate '
            . 'would then count as watched because somebody wrote its name in a comment, '
            . 'which is precisely how `composer qa` came to be documented as the mandatory '
            . 'gate while no workflow ran it. Read: ' . implode(', ', $declared),
        );
    }

    /**
     * A negative test whose refusal has been deleted stops backing its declaration.
     */
    #[Test]
    public function itReportsADeclarationWhoseTestNoLongerWatchesARefusal(): void
    {
        $hollow = <<<'PHP'
            <?php
            final class StillGreenTest
            {
                public function itRunsTheGate(): void
                {
                    // The failing case was deleted when it went red for an unrelated reason.
                    // What survives asserts the gate is happy about a healthy repository,
                    // which distinguishes nothing at all.
                    self::assertSame(0, $this->runGate([]));
                }
            }
            PHP;

        self::assertFalse(
            GateInventory::observesRefusal($hollow),
            'a test that only ever asserts exit code 0 was read as observing a refusal. With '
            . 'a #[GuardsGate] attached, the enumeration would report the gate as watched '
            . 'while nobody has watched anything -- the same claim-without-observation that '
            . 'left nine gates green.',
        );

        $real = <<<'PHP'
            <?php
            final class ActuallyRefusesTest
            {
                public function itRefusesThePlantedDefect(): void
                {
                    self::assertSame(1, $this->runGate(['--root', $tree]));
                }
            }
            PHP;

        self::assertTrue(
            GateInventory::observesRefusal($real),
            'an assertion that the exit code is 1 was not recognised as observing a refusal, '
            . 'so real negative tests would be reported as hollow and the honest fix would '
            . 'look like adding an exemption.',
        );
    }

    /**
     * A comment naming a script is not an invocation of it.
     *
     * Live in this repository, not hypothetical: ci.yml carries a comment naming
     * tools/ci/check-version-consistency.sh, a file that has been deleted.
     */
    #[Test]
    public function itDoesNotReadACommentedOutCommandAsRunningAGate(): void
    {
        $tree = $this->plantRepository();

        // The script has to exist for the second half to mean anything: the scan requires
        // the file to be on disk, so a fixture that omits it would pass the negative
        // assertion for the wrong reason and never reach the property being tested.
        $this->plantFile($tree, 'tools/ci/assert-nobody-watches-this.php', "<?php\nexit(1);\n");

        $inventory = new GateInventory($tree);

        $commented = <<<'YAML'
                    # This used to be php tools/ci/assert-nobody-watches-this.php, which we
                    # removed when the check moved into PHP.
                    run: echo "nothing to see"
            YAML;

        self::assertSame(
            [],
            $inventory->scriptsInvokedIn($commented),
            'a commented-out command was read as a running gate. The inventory would then '
            . 'demand a negative test for a script nobody executes, and -- worse in the other '
            . 'direction -- would report a deleted script as a live gate.',
        );

        self::assertSame(
            ['tools/ci/assert-nobody-watches-this.php'],
            $inventory->scriptsInvokedIn('run: php tools/ci/assert-nobody-watches-this.php'),
            'an ordinary invocation was not recognised, so the scan that is supposed to find '
            . 'gates finds nothing and the enumeration passes over an empty list.',
        );
    }

    /**
     * A step that cannot fail the build is not counted as a gate.
     *
     * benchmark-nightly.yml is the live example: both substantive steps carry
     * continue-on-error, so the job named "Tier B: Nightly Performance Benchmarks" cannot
     * fail on a breached budget. Counting it would put a gate in the inventory that has no
     * refusal to observe; missing that a real gate had gained the flag would be worse.
     */
    #[Test]
    public function itDoesNotCountAStepThatIsAllowedToFail(): void
    {
        $tree = $this->plantRepository();

        $this->plantFile($tree, '.github/workflows/tolerant.yml', <<<'YAML'
            jobs:
              report:
                steps:
                  - name: Looks like a gate, cannot fail
                    continue-on-error: true
                    run: php tools/ci/assert-nobody-watches-this.php
            YAML);

        $this->plantFile($tree, 'tools/ci/assert-nobody-watches-this.php', "<?php\nexit(1);\n");

        $inventory = new GateInventory($tree);
        $tolerated = array_map(
            static fn(object $step): string => $step->name,
            $inventory->toleratedSteps(),
        );

        self::assertContains(
            'Looks like a gate, cannot fail',
            $tolerated,
            'a step carrying continue-on-error was not recognised as tolerated, so a step '
            . 'that stopped being able to block a merge would keep counting as one.',
        );

        self::assertArrayNotHasKey(
            'tools/ci/assert-nobody-watches-this.php',
            $inventory->scriptsInvokedByBlockingSteps(),
            'a step that cannot fail the build was counted as running a gate.',
        );
    }

    /**
     * The checker/worker split survives the shape that broke the first version.
     *
     * scripts/wiring_check.php ends `exit($unwiredCount > 0 ? 1 : 0)`. The original rule
     * looked for a digit straight after the parenthesis and therefore classified a real
     * gate as a library, dropping it from the inventory silently and in the direction of
     * "nothing to cover here".
     */
    #[Test]
    public function itRecognisesARefusalThatIsComputedRatherThanWritten(): void
    {
        self::assertTrue(
            GateInventory::canRefuse('<?php exit($unwiredCount > 0 ? 1 : 0);'),
            'a script whose non-zero exit is computed was classified as unable to refuse. It '
            . 'would drop out of the gate inventory entirely, and the enumeration would '
            . 'report full coverage over a list with a hole in it.',
        );

        self::assertTrue(
            GateInventory::canRefuse("#!/usr/bin/env bash\nif [ -n \"\$bad\" ]; then\n  exit 1\nfi\n"),
            'a shell gate was classified as unable to refuse.',
        );

        self::assertFalse(
            GateInventory::canRefuse("<?php\n// A worker: it reports to its parent, it does not judge.\nexit(0);"),
            'a worker was swept into the gate inventory, which fills the enumeration with '
            . 'rows nobody can write a negative test for and makes exemptions look routine.',
        );
    }

    /**
     * An unnamed step is still a step.
     *
     * ci.yml's `js` job is four bare `- run:` entries. A parser keyed on `- name:` sees
     * none of them, and the four JS gates leave the inventory without anything going red.
     */
    #[Test]
    public function itSeesStepsThatCarryNoName(): void
    {
        $tree = $this->plantRepository();

        $this->plantFile($tree, '.github/workflows/bare.yml', <<<'YAML'
            jobs:
              js:
                steps:
                  - run: pnpm install --frozen-lockfile
                  - run: pnpm lint
            YAML);

        $this->plantFile($tree, 'package.json', '{"scripts":{"lint":"eslint ."}}');

        $gates = array_map(
            static fn(Gate $gate): string => $gate->id,
            new GateInventory($tree)->gates(),
        );

        self::assertTrue(
            in_array('pnpm lint', $gates, true),
            'a workflow step written without a `name:` was invisible to the step walk, so '
            . 'every gate in ci.yml\'s js job -- lint, format:check, typecheck, test -- would '
            . 'be missing from the inventory while the enumeration reported success. Found: '
            . implode(', ', $gates),
        );
    }

    /**
     * A miniature repository with one gate declared the way gates are declared here.
     */
    private function plantRepository(): string
    {
        $tree = $this->plantTree('gate-enumeration');

        $this->plantFile($tree, 'composer.json', <<<'JSON'
            {
                "scripts": {
                    "nobody-watches": "php tools/ci/assert-nobody-watches-this.php",
                    "qa": ["@nobody-watches"]
                }
            }
            JSON);

        return $tree;
    }
}
