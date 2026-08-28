<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\PlantsDefectsForGates;

use function file_get_contents;
use function json_decode;
use function str_contains;
use function str_replace;

/**
 * Plants what `composer boundary:deptrac` exists to refuse, and observes the refusal.
 *
 * The structural half of ADR-0002 / ADR-0009 lives in tools/php/deptrac.yaml: 3855
 * lines of layer collectors and a ruleset saying which layer may reach which. Two
 * tests referenced it before this one, and both ran it against the healthy
 * repository and asserted exit 0 — tests/Integration/Boundary/DeptracConfigTest's
 * `deptrac_analyse_passes()` and `boundary_custom_script_passes()`. An empty
 * `paths:`, a `ruleset:` in which every layer may reach every other, or a collector
 * regex that matches nothing would all pass those two exactly as the healthy
 * configuration does.
 *
 * So this plants a violation the ruleset names in so many words — a Foundation-tier
 * class reaching into another module's `\Internal\` namespace, which is the single
 * rule the whole per-module-internal layout exists to express — and requires deptrac
 * to refuse it.
 *
 * It also plants the mirror-image dependency that IS allowed (an `\Internal\` class
 * depending on Foundation) and requires that one to be counted as allowed, because a
 * configuration that refuses everything is as useless as one that refuses nothing and
 * an exit code alone cannot tell them apart.
 *
 * WHY THE CONFIGURATION IS DERIVED
 *
 * Deptrac takes its scan roots only from `paths:`; there is no command-line path
 * argument to point it somewhere else. The derived file replaces that one block with
 * the fixture directory and changes nothing else — every other key in the file is a
 * regex or a layer name, so nothing else in it is path-dependent. {@see
 * theDerivationReplacesThePathsBlockAndNothingElse} reverses the substitution and
 * compares against the real file byte for byte.
 */
#[GuardsGate(gate: 'composer boundary:deptrac', plants: 'a Foundation-tier class importing another module\'s \\Internal\\ namespace')]
final class DeptracGateTest extends TestCase
{
    use PlantsDefectsForGates;

    private const string CONFIG = 'tools/php/deptrac.yaml';

    /**
     * The exact `paths:` block, matched verbatim so a reshuffle of the real file
     * fails loudly here instead of silently deriving a config that scans nothing.
     */
    private const string REAL_PATHS = "  paths:\n    - ../../src/\n    - ../../extensions/\n";

    /** Foundation tier reaching into another module's internals: forbidden. */
    private const string FORBIDDEN = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Pulsar\Support;

        use Pulsar\Auth\Internal\ProbeSecret;

        final class ProbeHelper
        {
            public function __construct(private readonly ProbeSecret $secret) {}
        }
        PHP;

    /** The same edge in the legal direction: internals may reach Foundation. */
    private const string ALLOWED = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Pulsar\Auth\Internal;

        use Pulsar\Support\Str;

        final class ProbeSecret
        {
            public function owner(): string
            {
                return Str::class;
            }
        }
        PHP;

    protected function tearDown(): void
    {
        $this->assertNothingWasLeftBehind();
    }

    #[Test]
    public function itRefusesAFoundationClassReachingIntoAnotherModulesInternals(): void
    {
        $tree = $this->plantTree('deptrac');
        $this->plantFile($tree, 'fixture/Support/ProbeHelper.php', self::FORBIDDEN);
        $this->plantFile($tree, 'fixture/Auth/ProbeSecret.php', self::ALLOWED);

        [$status, $stdout] = $this->analyse($tree);

        self::assertSame(
            1,
            $status,
            "composer boundary:deptrac accepted Pulsar\\Support\\ProbeHelper depending on\n"
            . "Pulsar\\Auth\\Internal\\ProbeSecret. Had it stayed silent, ADR-0002's structural half\n"
            . "would no longer exist: any module could import any other module's \\Internal\\ classes,\n"
            . "and the per-module internal layers in tools/php/deptrac.yaml would be decoration.\n"
            . $stdout,
        );

        self::assertStringContainsString(
            'Pulsar\\\\Support\\\\ProbeHelper must not depend on Pulsar\\\\Auth\\\\Internal\\\\ProbeSecret',
            $stdout,
            'deptrac failed for some reason other than the planted violation, so the exit code above '
            . 'is not evidence about the boundary ruleset',
        );

        /** @var array{Report?: array{Violations?: int, Allowed?: int, Errors?: int}} $report */
        $report = json_decode($stdout, true);

        self::assertIsArray($report);
        self::assertArrayHasKey('Report', $report);
        self::assertSame(1, $report['Report']['Violations'] ?? null);
        self::assertSame(
            0,
            $report['Report']['Errors'] ?? null,
            'deptrac reported an internal error, so its violation count is not trustworthy',
        );

        // The legal edge, counted. Without this the test would also pass against a
        // ruleset that forbids every dependency in the codebase, which would be a
        // gate nobody could keep green and therefore a gate that gets deleted.
        self::assertSame(
            1,
            $report['Report']['Allowed'] ?? null,
            'deptrac no longer allows an \\Internal\\ class to depend on the Foundation tier, so the '
            . 'ruleset has stopped distinguishing legal dependencies from illegal ones',
        );
    }

    #[Test]
    public function theDerivationReplacesThePathsBlockAndNothingElse(): void
    {
        $real = $this->realConfiguration();
        $tree = $this->plantTree('deptrac-derivation');
        $derived = $this->deriveConfiguration($tree);

        self::assertStringContainsString('- ' . $tree . '/fixture' . "\n", $derived);
        self::assertSame(
            $real,
            str_replace("  paths:\n    - " . $tree . "/fixture\n", self::REAL_PATHS, $derived),
            "the derivation now changes something beyond the paths block, so the analysed ruleset is\n"
            . 'no longer the ruleset CI runs.',
        );
    }

    /**
     * `paths:` is the one part of the configuration a derived run cannot exercise.
     */
    #[Test]
    public function theRealConfigurationStillScansSourceAndExtensions(): void
    {
        self::assertStringContainsString(
            self::REAL_PATHS,
            $this->realConfiguration(),
            'tools/php/deptrac.yaml no longer scans src/ and extensions/: boundary:deptrac would '
            . 'analyse nothing and report success, which is what the gate looks like when it works',
        );
    }

    private function realConfiguration(): string
    {
        $real = file_get_contents($this->repositoryRoot() . '/' . self::CONFIG);

        self::assertIsString($real, 'could not read ' . self::CONFIG);

        return $real;
    }

    private function deriveConfiguration(string $tree): string
    {
        $real = $this->realConfiguration();

        self::assertTrue(
            str_contains($real, self::REAL_PATHS),
            self::CONFIG . " no longer opens with the paths block this test replaces. Update REAL_PATHS\n"
            . 'rather than loosening the match, or the derived run silently scans nothing.',
        );

        return str_replace(self::REAL_PATHS, "  paths:\n    - " . $tree . "/fixture\n", $real);
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function analyse(string $tree): array
    {
        // The derived file lives in the fixture tree, not next to the real one:
        // every remaining key in deptrac.yaml is a regex or a layer name, so none of
        // them resolves against the configuration's directory.
        $config = $this->plantFile($tree, 'deptrac.yaml', $this->deriveConfiguration($tree));

        return $this->runGate([
            'vendor/bin/deptrac',
            'analyse',
            '--config-file=' . $config,
            '--no-progress',
            '--formatter=json',
        ]);
    }
}
