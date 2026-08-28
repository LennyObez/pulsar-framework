<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\PlantsDefectsForGates;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function random_bytes;
use function str_contains;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function substr_replace;
use function unlink;

/**
 * Plants what `composer psalm` exists to refuse, and observes the refusal.
 *
 * Two independent things are held here, and only one of them is the type checker.
 *
 * FIRST, errorLevel="1". A method declared `: int` whose only statement returns a
 * string must be refused. That half needs no ceremony.
 *
 * SECOND, and this is the part nothing watched: `findUnusedCode="true"` is a gate on
 * dead code, and tools/php/psalm-plugin/Hook/ContainerResolutionDetector.php exists
 * to make that gate report less. The hook flips `ClassLikeStorage::$public_api` on
 * any class matching one of seventeen rules, several of them broad enough to cover
 * much of the repository on their own — `*Service`, `*Manager`, `*Exception`,
 * `*Controller`, anything under a `\Internal\` namespace. A hook that grew one rule
 * too far would silently switch the dead-code half of `composer psalm` off, and the
 * only symptom would be a gate that never complains, which is what a healthy gate
 * also looks like.
 *
 * (The gate enumeration describes this hook as catching "service-locator style
 * container resolution outside the composition root". It does not: it reports
 * nothing at all and can only ever suppress. Hence assertions about what it must NOT
 * suppress.)
 *
 * So the fixture carries a matched pair. `OrphanWidget` matches no rule and must be
 * reported unused; `OrphanWidgetService` differs from it only by a suffix rule R12
 * recognises and must not be. If the plugin stops loading, the second is reported
 * and this fails; if a rule grows wide enough to swallow the first, this fails too.
 * Either failure is the one a silent gate cannot give you.
 *
 * WHY THE CONFIGURATION IS DERIVED RATHER THAN USED VERBATIM
 *
 * Psalm reports unused code only on a whole-project run: naming a path inside an
 * otherwise-normal invocation disables that detection entirely — measured against
 * this same fixture, which reports UnusedClass under a project run and nothing under
 * a path run. So the fixture has to BE the project for one run, and exactly two
 * things change to make that true. {@see theDerivationChangesTwoThingsAndNothingElse}
 * reverses both and compares against the real file byte for byte, so the derivation
 * cannot quietly acquire a third change that loosens the run.
 */
#[GuardsGate(gate: 'psalm hook ContainerResolutionDetector', plants: 'a final class nothing references that matches no hook rule, beside one differing only by a suffix rule R12 recognises which must not be reported')]
#[GuardsGate(gate: 'composer psalm', plants: 'a method declared `: int` that returns a string, and a final class nothing references that no ContainerResolutionDetector rule exempts')]
final class PsalmGateTest extends TestCase
{
    use PlantsDefectsForGates;

    private const string CONFIG = 'tools/php/psalm.xml';

    /**
     * The one switch the derivation turns off, and its exact length, because the
     * replacement is positional.
     */
    private const string SUPPRESSION_SWITCH_ON = 'findUnusedIssueHandlerSuppression="true"';

    private const string SUPPRESSION_SWITCH_OFF = 'findUnusedIssueHandlerSuppression="false"';

    /**
     * Matches no rule in ContainerResolutionDetector: no attribute, no registry
     * interface, no framework base class, and a name ending in nothing the hook
     * recognises.
     */
    private const string ORPHAN = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace PulsarGateProbe;

        final class OrphanWidget
        {
            public function label(): string
            {
                return 'orphan';
            }
        }
        PHP;

    /** The same class with a suffix rule R12 recognises: equally dead, deliberately exempt. */
    private const string ORPHAN_SERVICE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace PulsarGateProbe;

        final class OrphanWidgetService
        {
            public function label(): string
            {
                return 'orphan';
            }
        }
        PHP;

    /**
     * Named `*Repository` on purpose: rule R8 exempts it from the unused-class
     * check, so the only thing it can be refused for is its type error.
     */
    private const string WIDENED_RETURN = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace PulsarGateProbe;

        final class WidenedReturnRepository
        {
            public function widen(string $value): int
            {
                return $value;
            }
        }
        PHP;

    private ?string $derivedConfig = null;

    protected function tearDown(): void
    {
        // The derived configuration is the only file this class writes inside the
        // working tree, so its removal is asserted rather than attempted. A stray
        // psalm-gate-*.xml under tools/php/ is precisely the verification artefact
        // this whole class of test exists to keep out of a repository.
        if ($this->derivedConfig !== null) {
            $path = $this->derivedConfig;
            $this->derivedConfig = null;

            if (is_file($path)) {
                unlink($path);
            }

            self::assertFileDoesNotExist($path, 'the derived Psalm configuration survived the test');
        }

        $this->assertNothingWasLeftBehind();
    }

    #[Test]
    public function itRefusesTheDefectsTheConfiguredAnalysisExistsToCatch(): void
    {
        $tree = $this->plantTree('psalm');
        $this->plantFile($tree, 'OrphanWidget.php', self::ORPHAN);
        $this->plantFile($tree, 'OrphanWidgetService.php', self::ORPHAN_SERVICE);
        $this->plantFile($tree, 'WidenedReturn.php', self::WIDENED_RETURN);

        [$status, $stdout] = $this->analyseAsProject($tree);

        self::assertNotSame(
            0,
            $status,
            "composer psalm accepted a project containing a method declared `: int` that returns a\n"
            . "string and a final class nothing references. Had it stayed silent, errorLevel=\"1\" would\n"
            . "no longer be in force and dead code would ship unremarked.\n" . $stdout,
        );

        self::assertStringContainsString(
            'InvalidReturnStatement',
            $stdout,
            'errorLevel="1" no longer refuses a return statement that contradicts its declared type',
        );
        self::assertStringContainsString(
            'InvalidReturnType',
            $stdout,
            'errorLevel="1" no longer refuses a return type the body cannot produce',
        );

        self::assertStringContainsString(
            'UnusedClass: Class PulsarGateProbe\OrphanWidget is never used',
            $stdout,
            "findUnusedCode no longer reports a class nothing references. Either the switch is off in\n"
            . "tools/php/psalm.xml or ContainerResolutionDetector has grown a rule wide enough to cover\n"
            . "an ordinary final class — and with it the dead-code half of composer psalm, which would\n"
            . 'then pass over any amount of unreachable code without a word.',
        );

        // The other direction, because a suppressor that suppresses nothing has
        // stopped being loaded, and the first thing that follows is somebody
        // deleting container-wired code to satisfy an analyser that called it dead.
        self::assertStringNotContainsString(
            'PulsarGateProbe\OrphanWidgetService is never used',
            $stdout,
            "the Psalm plugin in tools/php/psalm-plugin/ is no longer registered by tools/php/psalm.xml,\n"
            . "so every class the framework resolves through a registry or a naming convention now reads\n"
            . 'as dead.',
        );
    }

    #[Test]
    public function theDerivationChangesTwoThingsAndNothingElse(): void
    {
        $real = $this->realConfiguration();
        $tree = $this->plantTree('psalm-derivation');
        $derived = $this->deriveConfiguration($tree);

        foreach (['errorLevel="1"', 'findUnusedCode="true"', 'PulsarPsalmPlugin'] as $needle) {
            self::assertStringContainsString($needle, $real, $needle . ' is gone from ' . self::CONFIG);
            self::assertStringContainsString(
                $needle,
                $derived,
                $needle . ' was lost in the derivation, so the run above proves nothing about it',
            );
        }

        self::assertStringContainsString(self::SUPPRESSION_SWITCH_ON, $real);
        self::assertStringContainsString(self::SUPPRESSION_SWITCH_OFF, $derived);
        self::assertStringContainsString('<directory name="' . $tree . '"/>', $derived);

        // Reverse both changes and the real file must come back byte for byte.
        $restored = str_replace(self::SUPPRESSION_SWITCH_OFF, self::SUPPRESSION_SWITCH_ON, $derived);
        $start = (int) strpos($restored, '<projectFiles>');
        $end = (int) strpos($restored, '</projectFiles>');
        $realStart = (int) strpos($real, '<projectFiles>');
        $realEnd = (int) strpos($real, '</projectFiles>');

        self::assertSame(
            $real,
            substr_replace($restored, substr($real, $realStart, $realEnd - $realStart), $start, $end - $start),
            "the derivation now changes something beyond <projectFiles> and the one named switch, so\n"
            . 'the analysed configuration is no longer the configuration CI runs.',
        );
    }

    private function realConfiguration(): string
    {
        $real = file_get_contents($this->repositoryRoot() . '/' . self::CONFIG);

        self::assertIsString($real, 'could not read ' . self::CONFIG);

        return $real;
    }

    /**
     * Writes the derived configuration next to the real one, because every other
     * path inside it — the stubs, the cache directory, the per-file issue handlers —
     * is relative to tools/php/ and `resolveFromConfigFile="true"` means exactly
     * that.
     */
    private function deriveConfiguration(string $tree): string
    {
        $configuration = $this->realConfiguration();
        $start = strpos($configuration, '<projectFiles>');
        $end = strpos($configuration, '</projectFiles>');

        self::assertIsInt($start, self::CONFIG . ' has no <projectFiles> element');
        self::assertIsInt($end, self::CONFIG . ' has no </projectFiles> element');

        $derived = substr_replace(
            $configuration,
            '<projectFiles>' . "\n"
            . '        <directory name="' . $tree . '"/>' . "\n"
            . '        <ignoreFiles><directory name="../../vendor"/></ignoreFiles>' . "\n" . '    ',
            $start,
            $end - $start,
        );

        // Every <issueHandlers> entry names a file under src/ that a fixture-only
        // project does not analyse, so all of them would report as unused and the
        // exit code would stop being attributable to the planted defect. The switch
        // stays on in the real configuration, which is what the gate depends on.
        self::assertTrue(
            str_contains($derived, self::SUPPRESSION_SWITCH_ON),
            self::CONFIG . ' no longer carries ' . self::SUPPRESSION_SWITCH_ON . ', so the derivation is stale',
        );

        return substr_replace(
            $derived,
            self::SUPPRESSION_SWITCH_OFF,
            (int) strpos($derived, self::SUPPRESSION_SWITCH_ON),
            strlen(self::SUPPRESSION_SWITCH_ON),
        );
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function analyseAsProject(string $tree): array
    {
        $path = $this->repositoryRoot() . '/tools/php/psalm-gate-' . bin2hex(random_bytes(6)) . '.xml';
        $this->derivedConfig = $path;

        self::assertNotFalse(file_put_contents($path, $this->deriveConfiguration($tree)));

        return $this->runGate([
            'vendor/bin/psalm',
            '--config=' . $path,
            '--no-progress',
            '--no-cache',
            '--output-format=text',
        ]);
    }
}
