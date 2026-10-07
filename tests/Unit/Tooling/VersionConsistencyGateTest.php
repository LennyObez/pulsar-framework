<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\PlantedGitRepository;
use Pulsar\Tests\Unit\Tooling\Support\PlantsDefectsForGates;

use function str_replace;

/**
 * Plants what `composer version:check` exists to refuse, and observes the refusal.
 *
 * The gate was written because the repository had already drifted and nothing had
 * noticed: 26 bundled manifests at `1.0.0-rc.11`, seven claiming a `1.0.0` the framework
 * has not released, one still at `0.2.0`, and — the one that mattered — a
 * supported-versions table telling anyone about to report a vulnerability that fixes went
 * to a release that was no longer current. Every one of those was a string that was
 * perfectly valid on its own; only the comparison was missing.
 *
 * So the comparison is what gets planted here, one failure at a time, against the real
 * script through the `--root=` option its header documents. Every case is a shape the
 * repository was actually found in, or a shape it could rot into next:
 *
 *   - a manifest left at the previous release, which is how 26 of them were found;
 *   - a manifest claiming a stable version the framework has not reached, which is how
 *     seven of them were found;
 *   - a supported-versions table naming a release that is not the current one;
 *   - a declared site whose sentence was reworded, so the map that judges it silently
 *     stops judging anything — the way a gate turns into no gate;
 *   - a release tag on HEAD that disagrees with composer.json, which is the one
 *     disagreement consumers RESOLVE rather than read;
 *   - a brand-new file announcing an older release as the current one, which no map
 *     could have anticipated;
 *   - a `requires` floor naming a package the repository does not ship, so the floor
 *     could never have been satisfied or checked.
 *
 * Two cases exist to stop the gate from becoming a blanket ban on old version strings,
 * which would be the same mistake in the other direction. {@see
 * itLeavesHistoryAloneWhenItNamesTheReleaseSomethingAppearedIn()} plants an `@since`
 * marker and an `@deprecated Since` line naming an old release and requires the gate to
 * stay silent; {@see itAcceptsATreeInWhichEveryDeclaredVersionAgrees()} requires it to
 * stay silent on a whole healthy tree, without which every assertion below would also be
 * produced by a script that refuses its input unconditionally.
 */
#[GuardsGate(gate: 'tools/version/sync-version.php', plants: 'a bundled manifest left at the previous release, one claiming a release the framework has not made, a supported-versions table naming a version that is not current, a declared site reworded out of existence, a release tag disagreeing with composer.json, a new file announcing an old release as current, and a dependency floor naming a package the repository does not ship')]
final class VersionConsistencyGateTest extends TestCase
{
    use PlantsDefectsForGates;

    private const string SCRIPT = 'tools/version/sync-version.php';

    /** The version the planted tree is consistent at, wherever it is not perturbed. */
    private const string CURRENT = '1.0.0-rc.12';

    /** The release before it: what a site that was never updated still says. */
    private const string PREVIOUS = '1.0.0-rc.11';

    /** @var list<PlantedGitRepository> */
    private array $planted = [];

    protected function tearDown(): void
    {
        foreach ($this->planted as $repository) {
            $repository->remove();
            self::assertDirectoryDoesNotExist(
                $repository->path,
                'a planted fixture repository survived the test',
            );
        }

        $this->planted = [];
        $this->assertNothingWasLeftBehind();
    }

    #[Test]
    public function itAcceptsATreeInWhichEveryDeclaredVersionAgrees(): void
    {
        $tree = $this->plantConsistentRepository();

        [$status, $stdout, $stderr] = $this->check($tree);

        self::assertSame(
            0,
            $status,
            "the gate refuses a tree in which every declared version already agrees with\n"
            . "composer.json. Nothing below distinguishes a gate that judges from one that refuses\n"
            . "whatever it is handed, so this case has to hold before any of them mean anything.\n"
            . $stdout . $stderr,
        );
        self::assertStringContainsString('version: OK', $stdout);
    }

    /**
     * How 26 of the 34 bundled manifests were found.
     */
    #[Test]
    public function itRefusesABundledManifestLeftAtThePreviousRelease(): void
    {
        $tree = $this->plantConsistentRepository();
        $tree->write('extensions/cms/pulsar.json', $this->manifest('pulsar/cms', self::PREVIOUS, self::PREVIOUS));

        [$status, $stdout, $stderr] = $this->check($tree);

        self::assertSame(
            1,
            $status,
            "composer version:check accepted a bundled extension still declaring the previous\n"
            . "release as its own version. That is exactly the state 26 manifests were in for a\n"
            . "whole release cycle: every one of them loaded, every one of them reported a version\n"
            . 'the framework had left behind, and nothing was comparing them to anything.'
            . $stdout . $stderr,
        );
        self::assertStringContainsString('extensions/cms/pulsar.json', $stderr);
        self::assertStringContainsString('lockstep', $stderr);
    }

    /**
     * How the other seven were found: a manifest claiming the GA release, from inside a
     * release candidate, while binding to an RC in the same breath.
     */
    #[Test]
    public function itRefusesAManifestClaimingAReleaseTheFrameworkHasNotMade(): void
    {
        $tree = $this->plantConsistentRepository();
        $tree->write('extensions/cms/pulsar.json', $this->manifest('pulsar/cms', '1.0.0', self::CURRENT));

        [$status, , $stderr] = $this->check($tree);

        self::assertSame(
            1,
            $status,
            'composer version:check accepted an extension declaring 1.0.0 while the framework it '
            . 'ships inside is a release candidate, which is a stability claim nothing behind it '
            . 'supports.',
        );
        self::assertStringContainsString('declares version 1.0.0.', $stderr);
    }

    /**
     * The finding that made this gate urgent rather than tidy.
     */
    #[Test]
    public function itRefusesASupportedVersionsTableNamingAReleaseThatIsNotCurrent(): void
    {
        $tree = $this->plantConsistentRepository();
        $tree->write('.github/SECURITY.md', $this->securityPolicy(self::PREVIOUS));

        [$status, , $stderr] = $this->check($tree);

        self::assertSame(
            1,
            $status,
            "composer version:check accepted a security policy naming a release that is not the\n"
            . "current one as the one that receives fixes. Somebody deciding whether to report a\n"
            . 'vulnerability privately reads that table, and it was wrong for a whole release.',
        );
        self::assertStringContainsString('.github/SECURITY.md', $stderr);
        self::assertStringContainsString('receives security fixes', $stderr);
    }

    /**
     * The failure mode of the map itself, and the reason the map is safe to hand-write.
     *
     * A declared site is a pattern over a sentence. Reword the sentence and the pattern
     * matches nothing — and a check that matches nothing is a check that passes. That is
     * the shape ADR-0060 was written about, one level up from the versions.
     */
    #[Test]
    public function itRefusesADeclaredSiteThatHasBeenRewordedOutOfExistence(): void
    {
        $tree = $this->plantConsistentRepository();
        $tree->write('README.md', "# Pulsar\n\n> Currently in release candidate.\n");

        [$status, , $stderr] = $this->check($tree);

        self::assertSame(
            1,
            $status,
            "composer version:check went quiet when the sentence it judges was reworded away. A\n"
            . "site that stops matching stops being checked, and the gate would then report a\n"
            . 'repository as consistent on the strength of no longer looking at part of it.',
        );
        self::assertStringContainsString('nothing matches the declared site', $stderr);
    }

    /**
     * The one disagreement consumers resolve rather than read.
     */
    #[Test]
    public function itRefusesAReleaseTagOnHeadThatDisagreesWithComposerJson(): void
    {
        $tree = $this->plantConsistentRepository();
        $tree->commit('release');
        $tree->tag('v' . self::PREVIOUS);

        [$status, , $stderr] = $this->check($tree);

        self::assertSame(
            1,
            $status,
            "composer version:check accepted a release tag naming a version composer.json does not.\n"
            . "Packagist publishes the tag, so every consumer would resolve that version while the\n"
            . 'tree, the manifests and Version::full() all said another one.',
        );
        self::assertStringContainsString('v' . self::PREVIOUS, $stderr);
        self::assertStringContainsString('points at HEAD', $stderr);
    }

    /**
     * No map can list a file nobody has written yet, so the gate scans for what the maps
     * do not account for.
     */
    #[Test]
    public function itRefusesANewFileAnnouncingAnOlderReleaseAsTheCurrentOne(): void
    {
        $tree = $this->plantConsistentRepository();
        $tree->write('docs/quickstart.md', "# Quickstart\n\nPulsar " . self::PREVIOUS . " is the current release.\n");

        [$status, , $stderr] = $this->check($tree);

        self::assertSame(
            1,
            $status,
            "composer version:check accepted a new document announcing a superseded release as the\n"
            . "current one. Every drifted file this gate exists for arrived exactly that way — as a\n"
            . 'sentence somebody wrote once, that nothing afterwards was comparing to anything.',
        );
        self::assertStringContainsString('docs/quickstart.md', $stderr);
        self::assertStringContainsString('neither map', $stderr);
    }

    /**
     * The discrimination the whole design turns on.
     *
     * A blanket refusal of every old version string would be the same failure with the
     * sign flipped: it would refuse the ADRs, the changelog and every `@since` marker in
     * the public API, and the only way to get green would be to delete the project's
     * history. The gate has to tell a claim about the present from a record of the past,
     * and this is what holds it to that.
     */
    #[Test]
    public function itLeavesHistoryAloneWhenItNamesTheReleaseSomethingAppearedIn(): void
    {
        $tree = $this->plantConsistentRepository();
        $tree->write('src/Legacy/Adapter.php', <<<'PHP'
            <?php

            /**
             * @since 1.0.0-rc.9
             * @deprecated Since 1.0.0-rc.11. The framework handles this natively.
             */
            final class Adapter {}
            PHP);
        $tree->write('docs/adr/0001-a-decision.md', "Recorded at 1.0.0-rc.10, and not rewritten since.\n");
        $tree->write('CHANGELOG.md', "## [1.0.0-rc.11]\n\n- something that happened then\n");

        [$status, $stdout, $stderr] = $this->check($tree);

        self::assertSame(
            0,
            $status,
            "the gate refused a `@since` marker, an `@deprecated Since` line, an ADR and a changelog\n"
            . "entry — none of which claims to be the current release, and all of which are correct\n"
            . "exactly as written. A gate that cannot tell those from a stale declaration is a gate\n"
            . "whose only green state is a repository with no history.\n" . $stdout . $stderr,
        );
    }

    #[Test]
    public function itRefusesADependencyFloorNamingAPackageTheRepositoryDoesNotShip(): void
    {
        $tree = $this->plantConsistentRepository();
        $manifest = $this->manifest('pulsar/cms', self::CURRENT, self::CURRENT);
        $tree->write('extensions/cms/pulsar.json', str_replace(
            '"provides"',
            '"suggests": {"pulsar/runtime": ">=' . self::CURRENT . '"},' . "\n  " . '"provides"',
            $manifest,
        ));

        [$status, , $stderr] = $this->check($tree);

        self::assertSame(
            1,
            $status,
            'composer version:check accepted a dependency floor on a package this repository does '
            . 'not ship. Nothing can satisfy it and nothing can check it, so it reads as a '
            . 'compatibility statement while being a claim about nothing at all.',
        );
        self::assertStringContainsString('pulsar/runtime', $stderr);
    }

    /**
     * A gate whose repair does not satisfy it cannot be got green, and would be turned off
     * within a release.
     */
    #[Test]
    public function whatTheGateRefusesTheSyncRepairs(): void
    {
        $tree = $this->plantConsistentRepository();
        $tree->write('extensions/cms/pulsar.json', $this->manifest('pulsar/cms', self::PREVIOUS, self::PREVIOUS));
        $tree->write('.github/SECURITY.md', $this->securityPolicy(self::PREVIOUS));

        self::assertSame(1, $this->check($tree)[0], 'the tree to be repaired was already clean');

        [$syncStatus, $syncOut, $syncErr] = $this->runGate([self::SCRIPT, '--root=' . $tree->path]);

        self::assertSame(0, $syncStatus, 'the sync could not repair what the check refuses: ' . $syncOut . $syncErr);

        [$status, $stdout, $stderr] = $this->check($tree);

        self::assertSame(
            0,
            $status,
            "the gate still refuses a tree its own `composer version:sync` has just written. The\n"
            . "check and the generator would then be two policies rather than one, and the only way\n"
            . 'to land a release would be to stop running the check.' . $stdout . $stderr,
        );
    }

    /**
     * A source that cannot be read is not a source that agrees.
     */
    #[Test]
    public function itRefusesToJudgeATreeWhoseSourceVersionIsNotASemverString(): void
    {
        $tree = $this->plantConsistentRepository();
        $tree->write('composer.json', "{\n  \"name\": \"pulsar/framework\",\n  \"version\": \"latest\"\n}\n");

        [$status, , $stderr] = $this->check($tree);

        self::assertSame(
            2,
            $status,
            'the gate reported a verdict on a tree whose source of truth it could not parse. '
            . 'Exit 0 there would be the worst of the three outcomes: a clean report earned by '
            . 'having compared nothing.',
        );
        self::assertStringContainsString('not a semver string', $stderr);
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function check(PlantedGitRepository $tree): array
    {
        return $this->runGate([self::SCRIPT, '--check', '--root=' . $tree->path]);
    }

    /**
     * A tree carrying one of every kind of declaration the gate governs, all agreeing.
     *
     * Small on purpose: each file holds the one sentence that declares a version and
     * nothing else, so a failure below names the declaration rather than the fixture.
     */
    private function plantConsistentRepository(): PlantedGitRepository
    {
        $repository = PlantedGitRepository::create('version');
        $this->planted[] = $repository;

        $version = self::CURRENT;
        $short = 'rc.12';

        $repository->write('composer.json', <<<JSON
            {
              "name": "pulsar/framework",
              "version": "{$version}"
            }

            JSON);

        $repository->write('src/Core/Version.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pulsar\Core;

            final class Version
            {
                public const int MAJOR = 1;
                public const int MINOR = 0;
                public const int PATCH = 0;
                public const string PRERELEASE_SUFFIX = '-rc.12';
            }
            PHP);

        $repository->write('README.md', <<<MARKDOWN
            # Pulsar

            > **Status:** Release Candidate ({$version}). The rest of the line is not read.

            MARKDOWN);

        $repository->write('.github/SECURITY.md', $this->securityPolicy($version));

        $repository->write('.github/ISSUE_TEMPLATE/bug.yml', <<<YAML
            body:
              - type: dropdown
                id: pulsar-version
                attributes:
                  label: Pulsar version
                  options:
                    - {$version}
                    - Other

            YAML);

        $repository->write('ROADMAP.md', <<<MARKDOWN
            ### {$short} → 1.0.0 - GA gating work

            The following work stands between {$short} and the 1.0.0 GA tag.

            - Release notes + upgrade guide for `{$short} → 1.0.0`.

            MARKDOWN);

        $repository->write('docs/install.md', <<<MARKDOWN
            Pulsar Framework {$version}: Installation and setup.

            ```bash
            composer require pulsar/framework:^{$version}
            ```

            ```
            [INFO] Framework
              Version:      {$version}
            ```

            MARKDOWN);

        $repository->write('docs/extension-versioning.md', <<<MARKDOWN
            ```json
            {
              "name": "pulsar/admin",
              "version": "{$version}",
              "pulsar": {
                "min_version": "{$version}"
              }
            }
            ```

            MARKDOWN);

        $repository->write('docs/compliance.md', "This page reflects requirements as of Pulsar {$version}.\n");
        $repository->write('docs/prd-1.0.0.md', "1. {$short} (now) — the clusters that are closed.\n");
        $repository->write(
            'docs/architecture/superglobal-isolation.md',
            "## Current state ({$short})\n\nNot read beyond the heading.\n",
        );

        $repository->write('.github/workflows/benchmark-rc-gate.yml', <<<YAML
            on:
              workflow_dispatch:
                inputs:
                  rc_version:
                    description: 'RC version being validated (e.g. {$version})'

            YAML);

        $repository->write(
            'resources/playground/catalog.html',
            "<p>The current release is {$version}. The rest of the sentence is not read.</p>\n",
        );

        $repository->write('extensions/cms/pulsar.json', $this->manifest('pulsar/cms', $version, $version));
        $repository->write('extensions/admin/pulsar.json', $this->manifest('pulsar/admin', $version, $version));

        return $repository;
    }

    private function manifest(string $name, string $version, string $minVersion): string
    {
        return <<<JSON
            {
              "name": "{$name}",
              "version": "{$version}",
              "trust_tier": "core",
              "extension_class": "Pulsar\\\\Extension\\\\Fixture\\\\FixtureExtension",
              "pulsar": {
                "min_version": "{$minVersion}"
              },
              "provides": {
                "routes": false
              }
            }

            JSON;
    }

    private function securityPolicy(string $version): string
    {
        return <<<MARKDOWN
            ## Supported versions

            | Version       | Supported |
            | ------------- | --------- |
            | {$version}   | Yes       |
            | < {$version} | No        |

            MARKDOWN;
    }
}
