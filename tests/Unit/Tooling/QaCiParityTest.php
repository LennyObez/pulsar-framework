<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\InvokesCiScript;

use function is_file;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * Guards the check that keeps the local gate and the CI gate the same gate.
 *
 * This one cannot be exercised against the real composer.json / ci.yml pair — the
 * interesting cases are the ones where they DISAGREE, and producing those in the
 * repository is the thing the check exists to prevent. So it is run against
 * fixtures, through the `--composer` and `--workflow` options that exist for the
 * purpose.
 *
 * Two of the cases below are not hypothetical. The composite-expansion case is
 * there because `qa` lists `@boundary:check`, which is itself a list, and comparing
 * at the top level would have reported a match that was not one. The comment case
 * is there because the first version of the check DID pass on prose: a comment in
 * ci.yml reading "annotated rather than run as `composer boundary:custom`" was
 * counted as running it, and the check reported full parity while two gates were
 * covered by nothing but a sentence about them.
 */
#[GuardsGate(gate: 'tools/ci/assert-qa-ci-parity.php', plants: 'a workflow that skips a leaf of qa, an annotation naming a gate qa does not have, and a comment mentioning a command that must not count as running it')]
final class QaCiParityTest extends TestCase
{
    use InvokesCiScript;

    private const string SCRIPT = __DIR__ . '/../../../tools/ci/assert-qa-ci-parity.php';

    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->written = [];
    }

    #[Test]
    public function itPassesWhenTheWorkflowRunsEveryGate(): void
    {
        $composer = $this->composer(['qa' => ['@phpstan', '@psalm'], 'phpstan' => 'x', 'psalm' => 'y']);
        $workflow = $this->workflow(<<<'YAML'
            steps:
              - run: composer phpstan
              - run: composer psalm
            YAML);

        [$status, $stdout] = $this->invoke($composer, $workflow);

        self::assertSame(0, $status, $stdout);
        self::assertStringContainsString('All 2 gate(s)', $stdout);
    }

    #[Test]
    public function itFailsWhenTheWorkflowSkipsAGate(): void
    {
        $composer = $this->composer(['qa' => ['@phpstan', '@security:lint'], 'phpstan' => 'x', 'security:lint' => 'y']);
        $workflow = $this->workflow(<<<'YAML'
            steps:
              - run: composer phpstan
            YAML);

        [$status, , $stderr] = $this->invoke($composer, $workflow);

        self::assertSame(1, $status);
        self::assertStringContainsString('security:lint', $stderr);
        self::assertStringContainsString('does not run', $stderr);
    }

    /**
     * `qa` lists `@boundary:check`, which is itself `[@boundary:deptrac,
     * @boundary:custom]`. CI runs the two halves as separate steps, so a comparison
     * that stopped at the top level would call this a match on the strength of a
     * name nothing executes.
     */
    #[Test]
    public function itExpandsACompositeScriptIntoTheGatesItActuallyRuns(): void
    {
        $composer = $this->composer([
            'qa' => ['@boundary:check'],
            'boundary:check' => ['@boundary:deptrac', '@boundary:custom'],
            'boundary:deptrac' => 'deptrac analyse',
            'boundary:custom' => 'php scripts/boundary_check.php',
        ]);
        $workflow = $this->workflow(<<<'YAML'
            steps:
              - run: composer boundary:check
            YAML);

        [$status, , $stderr] = $this->invoke($composer, $workflow);

        self::assertSame(1, $status);
        self::assertStringContainsString('boundary:deptrac', $stderr);
        self::assertStringContainsString('boundary:custom', $stderr);
    }

    #[Test]
    public function itAcceptsAnAnnotationForAStepThatRunsTheGateAnotherWay(): void
    {
        $composer = $this->composer(['qa' => ['@test'], 'test' => 'phpunit']);
        $workflow = $this->workflow(<<<'YAML'
            steps:
              # qa-gate: test
              - name: Run all tests in parallel
                run: php vendor/bin/paratest
            YAML);

        [$status, $stdout] = $this->invoke($composer, $workflow);

        self::assertSame(0, $status, $stdout);
        self::assertStringContainsString('1 by annotation', $stdout);
    }

    /**
     * An annotation naming a gate that no longer exists is worse than no
     * annotation: it makes the workflow claim coverage nothing provides.
     */
    #[Test]
    public function itFailsOnAnAnnotationNamingAGateThatIsNotInQa(): void
    {
        $composer = $this->composer(['qa' => ['@phpstan'], 'phpstan' => 'x']);
        $workflow = $this->workflow(<<<'YAML'
            steps:
              - run: composer phpstan
              # qa-gate: qodana
              - run: ./run-something-else
            YAML);

        [$status, , $stderr] = $this->invoke($composer, $workflow);

        self::assertSame(1, $status);
        self::assertStringContainsString('qodana', $stderr);
    }

    /**
     * The regression that made the first version of this check useless.
     */
    #[Test]
    public function itDoesNotCountACommentMentioningACommandAsRunningIt(): void
    {
        $composer = $this->composer(['qa' => ['@psalm'], 'psalm' => 'x']);
        $workflow = $this->workflow(<<<'YAML'
            steps:
              # This step is annotated rather than run as `composer psalm`.
              - run: ./something-else
            YAML);

        [$status, , $stderr] = $this->invoke($composer, $workflow);

        self::assertSame(1, $status);
        self::assertStringContainsString('psalm', $stderr);
    }

    /**
     * CI is allowed to enforce MORE than the local gate — pull-request size, ADR
     * governance, dependency audit, coverage. Only the reverse is a defect.
     */
    #[Test]
    public function itAllowsCiToRunChecksThatAreNotPartOfQa(): void
    {
        $composer = $this->composer(['qa' => ['@phpstan'], 'phpstan' => 'x']);
        $workflow = $this->workflow(<<<'YAML'
            steps:
              - run: composer install
              - run: composer phpstan
              - run: composer audit --abandoned=report
              - run: bash tools/ci/check-pr-size.sh
            YAML);

        [$status, $stdout] = $this->invoke($composer, $workflow);

        self::assertSame(0, $status, $stdout);
    }

    #[Test]
    public function itRefusesToPassWhenComposerDeclaresNoQaScript(): void
    {
        $composer = $this->composer(['phpstan' => 'x']);
        $workflow = $this->workflow('steps: []');

        [$status, , $stderr] = $this->invoke($composer, $workflow);

        self::assertSame(2, $status);
        self::assertStringContainsString('no `qa` script', $stderr);
    }

    #[Test]
    public function itRefusesToPassWhenAFileCannotBeRead(): void
    {
        $workflow = $this->workflow('steps: []');

        [$status, , $stderr] = $this->runScript(
            self::SCRIPT,
            '--composer=' . sys_get_temp_dir() . '/pulsar-parity-absent.json',
            '--workflow=' . $workflow,
        );

        self::assertSame(2, $status);
        self::assertStringContainsString('could not read', $stderr);
    }

    #[Test]
    public function itRejectsAnUnknownArgumentRatherThanIgnoringIt(): void
    {
        [$status, , $stderr] = $this->runScript(self::SCRIPT, '--workflows=x');

        self::assertSame(2, $status);
        self::assertStringContainsString('Unrecognised argument', $stderr);
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function invoke(string $composer, string $workflow): array
    {
        return $this->runScript(self::SCRIPT, '--composer=' . $composer, '--workflow=' . $workflow);
    }

    /**
     * @param array<string, string|list<string>> $scripts
     */
    private function composer(array $scripts): string
    {
        return $this->write(json_encode(['scripts' => $scripts], JSON_THROW_ON_ERROR));
    }

    private function workflow(string $yaml): string
    {
        return $this->write($yaml);
    }

    private function write(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pulsar-parity-test-');
        self::assertIsString($path);

        file_put_contents($path, $contents);
        $this->written[] = $path;

        return $path;
    }
}
