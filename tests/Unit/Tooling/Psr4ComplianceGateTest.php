<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\PlantsDefectsForGates;

/**
 * Plants what `composer autoload:check` exists to refuse, and observes the refusal.
 *
 * tools/ci/assert-psr4-compliance.php wraps `composer dump-autoload --strict-psr`,
 * which only WARNS, into something that can fail a build. The class of defect it
 * catches is invisible in every other way: the optimized classmap resolves a
 * misplaced class anyway, so nothing breaks until a case-sensitive runner or a
 * parallel worker tries to autoload it by name, which is how 66 tests came to fail
 * at once.
 *
 * Three refusals are required here, and the third is the one the audit was about.
 *
 *   1. A class whose namespace contradicts its PSR-4 rule is named and refused.
 *   2. A class on the reviewed exception list is not — otherwise (1) would prove
 *      only that the script fails on something, not that it fails on the right thing.
 *   3. A run in which composer produced no classmap is refused rather than reported
 *      clean. Before this, the regex simply matched nothing and the script printed
 *      "OK: every class complies": a scan that reached nothing read exactly like a
 *      healthy tree, which is the shape of every finding this work exists to close.
 *
 * The `--root=` option the fixtures use was added for this, and its docblock in the
 * script says so.
 */
#[GuardsGate(gate: 'composer autoload:check', plants: 'a class declared in Fixture\\Wrong but filed under the PSR-4 root for Fixture\\, and a run in which composer produced no classmap at all')]
final class Psr4ComplianceGateTest extends TestCase
{
    use PlantsDefectsForGates;

    private const string SCRIPT = 'tools/ci/assert-psr4-compliance.php';

    /**
     * A minimal project: one PSR-4 rule, a vendor directory of its own so nothing
     * is written outside the fixture, and no dependencies to install.
     */
    private const string MANIFEST = <<<'JSON'
        {
            "name": "pulsar/psr4-gate-fixture",
            "version": "1.0.0",
            "description": "Fixture project for the PSR-4 compliance gate's negative test.",
            "autoload": {
                "psr-4": {
                    "Fixture\\": "src/"
                }
            },
            "config": {
                "vendor-dir": "vendor"
            }
        }
        JSON;

    protected function tearDown(): void
    {
        $this->assertNothingWasLeftBehind();
    }

    #[Test]
    public function itRefusesAClassWhoseNamespaceContradictsItsPsr4Rule(): void
    {
        $project = $this->plantProject('Misplaced', 'Fixture\\Wrong');

        [$status, $stdout, $stderr] = $this->runGate([self::SCRIPT, '--root=' . $project]);

        self::assertSame(
            1,
            $status,
            "composer autoload:check accepted a class declared in Fixture\\Wrong but filed under the\n"
            . "PSR-4 root for Fixture\\. Had it stayed silent, that class would ship unloadable by\n"
            . "name: it resolves only while something else happens to have included its file first,\n"
            . "which holds until the first case-sensitive runner or parallel worker.\n" . $stdout,
        );
        self::assertStringContainsString('Fixture\Wrong\Misplaced', $stderr);
        self::assertStringContainsString('PSR-4 violations (1)', $stderr);
    }

    /**
     * The reviewed exception list is what keeps (1) honest: if the script refused
     * every violation including the four it is meant to tolerate, the gate would be
     * unkeepable and would end up deleted or bypassed.
     */
    #[Test]
    public function itAcceptsTheViolationsTheExceptionListNames(): void
    {
        // ALLOWED_VIOLATIONS names this class verbatim: an extension-autoloader
        // fixture whose foreign namespace is the whole point of the fixture.
        $project = $this->plantProject('Widget', 'PulsarAutoloadFixture');

        [$status, $stdout, $stderr] = $this->runGate([self::SCRIPT, '--root=' . $project]);

        self::assertSame(
            0,
            $status,
            "the gate refused PulsarAutoloadFixture\\Widget, which tools/ci/assert-psr4-compliance.php\n"
            . "lists with a reason. Either the entry was dropped or the allowlist stopped being read;\n"
            . 'either way the gate now fails on code it was reviewed and agreed to tolerate.' . $stderr,
        );
        self::assertStringContainsString('1 documented exception(s) skipped', $stdout);
    }

    /**
     * The audited shape, in this gate's own terms.
     */
    #[Test]
    public function itRefusesARunInWhichComposerProducedNoClassmap(): void
    {
        $empty = $this->plantTree('psr4-empty');

        [$status, $stdout, $stderr] = $this->runGate([self::SCRIPT, '--root=' . $empty]);

        self::assertSame(
            1,
            $status,
            "the gate reported success over a directory composer could not read at all. Nothing was\n"
            . "checked, and 'OK: every class complies with its PSR-4 rule' was printed anyway — which\n"
            . "means a broken invocation in CI, a renamed working directory or a composer failure\n"
            . 'would each have been indistinguishable from a compliant repository.' . $stdout,
        );
        self::assertStringContainsString('no classmap, so nothing was checked', $stderr);
        self::assertStringContainsString('not a clean tree; it is an unmeasured one', $stderr);
    }

    /**
     * Exit 2 rather than 1, so a CI step that branches on "the tree is bad" cannot
     * swallow "the gate was invoked wrongly and measured nothing".
     */
    #[Test]
    public function itRefusesToRunOnAnOptionItDoesNotUnderstand(): void
    {
        [$status, , $stderr] = $this->runGate([self::SCRIPT, '--roott=/tmp']);

        self::assertSame(2, $status);
        self::assertStringContainsString('Unknown option: --roott=/tmp', $stderr);

        [$missing, , $missingStderr] = $this->runGate([self::SCRIPT, '--root=' . $this->plantTree('psr4-gone') . '/absent']);

        self::assertSame(2, $missing);
        self::assertStringContainsString('Not a directory', $missingStderr);
    }

    /**
     * A composer project whose single class sits under the given namespace while the
     * only PSR-4 rule maps `Fixture\` to src/.
     */
    private function plantProject(string $class, string $namespace): string
    {
        $project = $this->plantTree('psr4');

        $this->plantFile($project, 'composer.json', self::MANIFEST);
        $this->plantFile(
            $project,
            'src/' . $class . '.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace " . $namespace . ";\n\n"
            . 'final class ' . $class . " {}\n",
        );

        return $project;
    }
}
