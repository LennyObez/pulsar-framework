<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Version;
use Pulsar\Tests\Unit\Integrity\Support\ToolchainPinScanner;
use Pulsar\Tooling\Support\JsonDocument;

use function dirname;
use function implode;
use function version_compare;

/**
 * The toolchain version must have exactly one source of truth.
 *
 * It had none. Six workflows each wrote `php-version: '8.5'` — a minor line, so CI
 * silently took whatever patch the runner offered — and Node appeared as '20' in
 * one workflow and '22' in another, neither matching any developer machine. Three
 * versions of the same tool, nobody wrong, nobody aligned.
 *
 * Pin files fix that only if the workflows actually read them, which is what these
 * assertions hold in place. A literal version string reintroduced into any workflow
 * fails the build rather than quietly diverging for a few months.
 */
final class ToolchainPinningTest extends TestCase
{
    #[Test]
    public function thePinFilesExistAndCarryAnExactVersion(): void
    {
        $problems = $this->scanner()->pinsThatAreNotPatchExact();

        self::assertSame(
            [],
            $problems,
            "A pin file is the single source of truth for its runtime, and must name a\n"
            . "patch-exact version: a minor line lets CI and a laptop run different builds\n"
            . "while both look correctly configured.\n  " . implode("\n  ", $problems),
        );
    }

    #[Test]
    public function noWorkflowHardcodesARuntimeVersion(): void
    {
        $offenders = $this->scanner()->hardcodedRuntimeVersions();

        self::assertSame(
            [],
            $offenders,
            "Use `php-version-file: .php-version` and `node-version-file: .nvmrc` instead:\n  "
            . implode("\n  ", $offenders),
        );
    }

    #[Test]
    public function everyWorkflowThatSetsUpARuntimeReadsThePinFile(): void
    {
        self::assertNotSame(
            [],
            $this->scanner()->workflows(),
            'no workflows found — the check would pass vacuously',
        );

        $missing = $this->scanner()->workflowsIgnoringThePins();

        self::assertSame([], $missing, implode("\n  ", $missing));
    }

    /**
     * The pinned interpreter must actually satisfy what the package declares, or the
     * pin is a second opinion rather than a source of truth.
     */
    #[Test]
    public function thePinnedPhpSatisfiesTheComposerConstraint(): void
    {
        $pinned = $this->scanner()->pinned('.php-version');
        $constraint = JsonDocument::fromFile($this->root() . '/composer.json')
            ->child('require')
            ->string('php');

        $required = ToolchainPinScanner::constraintFloor($constraint);

        self::assertNotNull(
            $required,
            "This check understands a `>=x.y.z` constraint; composer.json now says '$constraint'.",
        );

        self::assertTrue(
            version_compare($pinned, $required, '>='),
            ".php-version pins $pinned, below the $constraint that composer.json requires.",
        );
    }

    /**
     * The test configuration must be validated by the schema of the PHPUnit that reads it.
     *
     * The declaration is a hint to editors; PHPUnit itself never reads it, so nothing
     * objects when it falls behind — which is exactly what makes it worth pinning.
     * An editor validating against a schema a major version behind reports valid
     * options as unknown and says nothing about options that did not exist yet.
     */
    #[Test]
    public function theDeclaredTestSchemaTracksTheInstalledPhpunit(): void
    {
        self::assertSame(
            Version::series(),
            ToolchainPinScanner::declaredSchemaSeries($this->configPath()),
            'tools/php/phpunit.xml declares a schema for a different PHPUnit than the '
            . Version::series() . ' that composer installed.',
        );
    }

    /**
     * And the configuration must actually satisfy that schema.
     *
     * The version check above only proves the two numbers agree. This one proves the
     * file is valid under the schema PHPUnit itself ships — which is what catches a
     * misspelled attribute. A strictness option with a typo in its name is not an
     * error to PHPUnit: it is simply an attribute it does not recognise, so the
     * strictness silently never applies and the suite looks stricter than it is.
     */
    #[Test]
    public function theTestConfigurationValidatesAgainstThatSchema(): void
    {
        $schema = $this->root() . '/vendor/phpunit/phpunit/phpunit.xsd';

        self::assertFileExists($schema, 'the installed PHPUnit ships no schema to validate against');

        $problems = ToolchainPinScanner::schemaViolations($this->configPath(), $schema);

        self::assertSame([], $problems, "tools/php/phpunit.xml is invalid:\n  " . implode("\n  ", $problems));
    }

    private function configPath(): string
    {
        return $this->root() . '/tools/php/phpunit.xml';
    }

    /**
     * The rules themselves live in ToolchainPinScanner, which takes the root it reads.
     * That is what lets ToolchainPinningRefusesTest assemble a checkout whose pins have
     * drifted and watch each rule refuse it — an observation a scan wired to this
     * checkout could never produce, because this checkout is correctly pinned.
     */
    private function scanner(): ToolchainPinScanner
    {
        return new ToolchainPinScanner($this->root());
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
