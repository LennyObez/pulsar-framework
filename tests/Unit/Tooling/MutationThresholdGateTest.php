<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\PlantedGitRepository;
use Pulsar\Tests\Unit\Tooling\Support\PlantsDefectsForGates;

use function str_replace;

/**
 * Plants a document that states a mutation threshold nothing enforces, and
 * observes the refusal.
 *
 * THE DISAGREEMENT. infection.json5 sets `minCoveredMsi: 90` and no `minMsi`.
 * ADR-0042 says exactly that. docs/testing.md said "MSI ... Minimum: 80%" and
 * docs/prd-1.0.0.md said "CI gate: ... Infection MSI >= 80", both describing a
 * plain-MSI gate that has never run. Three sources, no agreement, and no
 * mechanism that could ever have noticed -- so the number a contributor read was
 * decided by which file they opened.
 *
 * The documents were aligned to the configuration rather than the other way
 * round, because ADR-0042's argument holds: `source` is narrowed to src/Auth,
 * src/Security and src/Audit for memory reasons, and a plain-MSI floor over a
 * deliberately narrowed scope fails for reasons that are not about test quality.
 * That decision is only worth something if it can be checked, which is what the
 * gate under test does and what this class watches it do.
 *
 * WHY THE FIXTURES ARE GIT REPOSITORIES. The gate takes its corpus from
 * `git ls-files --cached --others --exclude-standard`, and the `--others` half is
 * not decoration: this repository has already been bitten by a ratchet whose
 * search skipped untracked files, so it was green on a branch it would have
 * refused the moment that branch was committed. A new ADR is untracked for exactly
 * as long as it takes to review it. {@see itSeesADocumentNobodyHasCommittedYet}
 * plants the violation in an untracked file specifically, so that half of the
 * corpus is observed rather than assumed.
 */
#[CoversNothing]
#[GuardsGate(gate: 'tools/ci/assert-mutation-thresholds.php', plants: 'a document claiming a plain-MSI gate the configuration does not set, a covered-MSI figure that disagrees with minCoveredMsi, an uncommitted document carrying the same claim, a configuration with no threshold at all, and a corpus in which nobody mentions MSI')]
final class MutationThresholdGateTest extends TestCase
{
    use PlantsDefectsForGates;

    private const string SCRIPT = 'tools/ci/assert-mutation-thresholds.php';

    /** The shape of the real infection.json5, reduced to what the gate reads. */
    private const string CONFIG = <<<'JSON5'
        {
          source: {
            directories: ['src/Auth', 'src/Security', 'src/Audit'],
          },
          minCoveredMsi: 90,
        }
        JSON5;

    /** @var list<PlantedGitRepository> */
    private array $planted = [];

    protected function tearDown(): void
    {
        foreach ($this->planted as $repository) {
            $repository->remove();
            self::assertDirectoryDoesNotExist($repository->path, 'a planted fixture repository survived the test');
        }

        $this->planted = [];
        $this->assertNothingWasLeftBehind();
    }

    /**
     * The control, over the repository this test is running in.
     *
     * Every refusal below would also be produced by a script that refuses whatever
     * it is handed, and this is the case that tells the two apart. It is asserted
     * against the real tree rather than a fixture because it is also the assertion
     * that catches the shipped documents drifting.
     */
    #[Test]
    public function itAcceptsThisRepositoryAsItStands(): void
    {
        [$status, $stdout, $stderr] = $this->check();

        self::assertSame(
            0,
            $status,
            "the gate refuses this repository's own documents. A Markdown file states a mutation\n"
            . "threshold that infection.json5 does not enforce:\n" . $stdout . $stderr,
        );
        self::assertStringContainsString('mutation thresholds: OK', $stdout);
    }

    /**
     * The planted defect: the sentence docs/testing.md actually carried.
     */
    #[Test]
    public function itRefusesADocumentClaimingAPlainMsiGateThatIsNotConfigured(): void
    {
        $tree = $this->plantRepository('msi-plain-claim');
        $tree->write('infection.json5', self::CONFIG);
        $tree->write('docs/testing.md', "# Testing\n\n"
            . "- **MSI (mutation score indicator)**: percentage of mutations killed by tests. Minimum: 80%.\n");
        $tree->write('CHANGELOG.md', "# Changelog\n");
        $tree->write('ROADMAP.md', "# Roadmap\n");

        [$status, , $stderr] = $this->check($tree->path);

        self::assertSame(
            1,
            $status,
            'a document announcing an 80% MSI floor was accepted while infection.json5 configured '
            . 'no plain-MSI floor at all. What ships on that silence is what shipped for a release: '
            . 'a contributor reading a number nothing enforces, and reading a green mutation run as '
            . 'evidence that it held.',
        );
        self::assertStringContainsString('docs/testing.md:3', $stderr);
        self::assertStringContainsString('plain MSI threshold of 80', $stderr);
        self::assertStringContainsString('configures no minMsi', $stderr);
    }

    /**
     * The other direction: a covered-MSI figure that disagrees with the one
     * configured. A gate that only ever refused unconfigured thresholds would pass
     * this, and the number would then be free to drift.
     */
    #[Test]
    public function itRefusesACoveredMsiFigureThatDisagreesWithTheConfiguration(): void
    {
        $tree = $this->plantRepository('msi-covered-drift');
        $tree->write('infection.json5', self::CONFIG);
        $tree->write('docs/testing.md', "# Testing\n\n- **Covered MSI**: minimum 80%.\n");
        $tree->write('CHANGELOG.md', "# Changelog\n");
        $tree->write('ROADMAP.md', "# Roadmap\n");

        [$status, , $stderr] = $this->check($tree->path);

        self::assertSame(1, $status, 'a documented covered-MSI floor of 80 was accepted against a configured 90');
        self::assertStringContainsString('covered MSI threshold of 80', $stderr);
        self::assertStringContainsString('minCoveredMsi: 90', $stderr);
    }

    /**
     * The untracked half of the corpus, which is where this class of gate has
     * failed before.
     */
    #[Test]
    public function itSeesADocumentNobodyHasCommittedYet(): void
    {
        $tree = $this->plantRepository('msi-untracked');
        $tree->write('infection.json5', self::CONFIG);
        $tree->write('docs/testing.md', "# Testing\n\nCovered MSI is enforced at 90.\n");
        $tree->write('CHANGELOG.md', "# Changelog\n");
        $tree->write('ROADMAP.md', "# Roadmap\n");
        $tree->commit('documents that agree');

        // Written after the commit, so git has it only as an "other" file: a new
        // ADR under review, which is precisely when it is being read.
        $tree->write('docs/adr/0099-a-new-decision.md', "# 0099\n\nThe MSI gate is set at 70.\n");

        [$status, , $stderr] = $this->check($tree->path);

        self::assertSame(
            1,
            $status,
            'a threshold claimed in a file nobody had committed yet was invisible to the gate. '
            . 'That is the exact shape of a ratchet this repository has already been bitten by: '
            . 'green on the branch, refusing the moment the branch is committed, and read as an '
            . 'approval in between.',
        );
        self::assertStringContainsString('0099-a-new-decision.md', $stderr);
    }

    /**
     * A record of what a past release enforced is not a claim about today.
     */
    #[Test]
    public function itLeavesTheReleaseHistoryAndTheAuditArchiveAlone(): void
    {
        $tree = $this->plantRepository('msi-records');
        $tree->write('infection.json5', self::CONFIG);
        $tree->write('docs/testing.md', "# Testing\n\nCovered MSI is enforced at 90.\n");
        $tree->write('CHANGELOG.md', "# Changelog\n\n- rc.4 raised the MSI gate to 70.\n");
        $tree->write('ROADMAP.md', "# Roadmap\n\n- rc.6 ran with MSI at 75.\n");
        // Ignored, as in this repository: the corpus is what git lists.
        $tree->write('.gitignore', "docs/audit/\n");
        $tree->write('docs/audit/2026-07/findings.md', "infection.json5 declared minMsi 70 on the day.\n");

        [$status, $stdout, $stderr] = $this->check($tree->path);

        self::assertSame(
            0,
            $status,
            "the gate rewrote history: it refused a changelog entry recording what a past release\n"
            . "enforced. Aligning those to today's figure would falsify the record rather than fix\n"
            . 'a claim.' . $stdout . $stderr,
        );
    }

    /**
     * An exclusion that names a path which no longer exists is a permission
     * nobody is using, and the next file to take the name inherits it unread.
     */
    #[Test]
    public function itRefusesWhenAnExcludedRecordNoLongerExists(): void
    {
        $tree = $this->plantRepository('msi-stale-exclusion');
        $tree->write('infection.json5', self::CONFIG);
        $tree->write('docs/testing.md', "# Testing\n\nCovered MSI is enforced at 90.\n");
        $tree->write('CHANGELOG.md', "# Changelog\n");
        // ROADMAP.md deliberately absent.

        [$status, , $stderr] = $this->check($tree->path);

        self::assertSame(2, $status);
        self::assertStringContainsString('ROADMAP.md', $stderr);
        self::assertStringContainsString('excluded from this scan', $stderr);
    }

    /**
     * A configuration with no threshold at all is a mutation run that cannot
     * fail, and every sentence describing it would then be describing nothing.
     */
    #[Test]
    public function itRefusesAConfigurationThatEnforcesNoThresholdAtAll(): void
    {
        $tree = $this->plantRepository('msi-no-threshold');
        $tree->write('infection.json5', "{\n  source: {\n    directories: ['src'],\n  },\n}\n");
        $tree->write('docs/testing.md', "# Testing\n\nCovered MSI is enforced at 90.\n");
        $tree->write('CHANGELOG.md', "# Changelog\n");
        $tree->write('ROADMAP.md', "# Roadmap\n");

        [$status, , $stderr] = $this->check($tree->path);

        self::assertSame(
            2,
            $status,
            'a mutation configuration with neither threshold set was treated as a corpus with '
            . 'nothing to check. `composer mutation` then exits 0 whatever the score is, and the '
            . 'documents describing it are describing nothing.',
        );
        self::assertStringContainsString('neither minCoveredMsi nor minMsi', $stderr);
    }

    /**
     * Deleting the sentences must not be the way to satisfy the gate.
     */
    #[Test]
    public function itRefusesACorpusInWhichNobodyMentionsMsiAtAll(): void
    {
        $tree = $this->plantRepository('msi-silent-corpus');
        $tree->write('infection.json5', self::CONFIG);
        $tree->write('docs/testing.md', "# Testing\n\nRun the suite.\n");
        $tree->write('CHANGELOG.md', "# Changelog\n");
        $tree->write('ROADMAP.md', "# Roadmap\n");

        [$status, , $stderr] = $this->check($tree->path);

        self::assertSame(
            1,
            $status,
            'a documentation set that says nothing about the mutation gate was accepted. The gate '
            . 'would then be satisfiable by deleting the sentences, which is the failure mode it '
            . 'exists to close one level up.',
        );
        self::assertStringContainsString('No document mentions MSI', $stderr);
    }

    /**
     * The gate must not read a measurement as a floor.
     *
     * "the run reported 93.4% MSI" is somebody writing down what happened. A gate
     * that refused it would be teaching contributors to stop recording results,
     * and a check whose failures are noise is a check that gets switched off.
     */
    #[Test]
    public function itDoesNotMistakeARecordedScoreForAThreshold(): void
    {
        $tree = $this->plantRepository('msi-measurement');
        $tree->write('infection.json5', self::CONFIG);
        $tree->write('docs/testing.md', "# Testing\n\n"
            . "Covered MSI is enforced at 90.\n\n"
            . "The last full sweep reported 93.4% MSI over the scoped trees.\n");
        $tree->write('CHANGELOG.md', "# Changelog\n");
        $tree->write('ROADMAP.md', "# Roadmap\n");

        [$status, $stdout, $stderr] = $this->check($tree->path);

        self::assertSame(0, $status, $stdout . $stderr);
    }

    private function plantRepository(string $label): PlantedGitRepository
    {
        $repository = PlantedGitRepository::create($label);
        $this->planted[] = $repository;

        return $repository;
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function check(?string $root = null): array
    {
        $command = [$this->repositoryRoot() . '/' . self::SCRIPT];

        if ($root !== null) {
            $command[] = '--root=' . str_replace('\\', '/', $root);
        }

        return $this->runGate($command);
    }
}
