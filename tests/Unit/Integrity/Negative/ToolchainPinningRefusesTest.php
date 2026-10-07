<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Negative;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Tests\Support\FilesystemTestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Integrity\Support\PlantsFiles;
use Pulsar\Tests\Unit\Integrity\Support\ToolchainPinScanner;

use function dirname;
use function implode;
use function is_file;
use function version_compare;

/**
 * The toolchain-pinning rules, watched refusing.
 *
 * These rules exist because the project once had three answers to "which PHP does this
 * run": six workflows each writing `php-version: '8.5'` — a minor line, so CI took
 * whatever patch the runner offered — and Node appearing as '20' in one workflow and
 * '22' in another. Nobody was wrong and nobody was aligned.
 *
 * The pins end that only while the workflows keep reading them, and "keep reading them"
 * is a claim nobody had tested. Every assertion below assembles a checkout that has
 * drifted in one of the ways the originals drifted, and watches the corresponding rule
 * name it.
 */
#[CoversClass(ToolchainPinScanner::class)]
#[GuardsGate(
    gate: 'ToolchainPinningTest::thePinFilesExistAndCarryAnExactVersion',
    plants: 'a checkout whose .php-version names a minor line and whose .nvmrc is missing',
)]
#[GuardsGate(
    gate: 'ToolchainPinningTest::noWorkflowHardcodesARuntimeVersion',
    plants: "a workflow carrying php-version: '8.5' and node-version: '22'",
)]
#[GuardsGate(
    gate: 'ToolchainPinningTest::everyWorkflowThatSetsUpARuntimeReadsThePinFile',
    plants: 'a workflow using setup-php and setup-node without either -version-file input',
)]
#[GuardsGate(
    gate: 'ToolchainPinningTest::thePinnedPhpSatisfiesTheComposerConstraint',
    plants: 'a pin below the constraint, and a constraint shape the check cannot read',
)]
#[GuardsGate(
    gate: 'ToolchainPinningTest::theDeclaredTestSchemaTracksTheInstalledPhpunit',
    plants: 'a phpunit.xml declaring the schema of a different PHPUnit series',
)]
#[GuardsGate(
    gate: 'ToolchainPinningTest::theTestConfigurationValidatesAgainstThatSchema',
    plants: 'a phpunit.xml whose strictness attribute is misspelled',
)]
final class ToolchainPinningRefusesTest extends FilesystemTestCase
{
    use PlantsFiles;

    /** A workflow that does everything right, so drift is the only difference. */
    private const string CORRECT_WORKFLOW = <<<'YAML'
        name: ci
        on: [push]
        jobs:
          build:
            runs-on: ubuntu-latest
            steps:
              - uses: shivammathur/setup-php@v2
                with:
                  php-version-file: .php-version
              - uses: actions/setup-node@v4
                with:
                  node-version-file: .nvmrc
        YAML;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->plant($this->tempDirectory, '.php-version', "8.5.9\n");
        $this->plant($this->tempDirectory, '.nvmrc', "22.11.0\n");
        $this->plant($this->tempDirectory, '.github/workflows/ci.yml', self::CORRECT_WORKFLOW);
    }

    #[Test]
    public function itRefusesAPinThatNamesALineRatherThanAVersion(): void
    {
        // The original defect: a minor line, which resolves to whatever the runner has.
        $this->plant($this->tempDirectory, '.php-version', "8.5\n");

        $problems = $this->scanner()->pinsThatAreNotPatchExact();

        self::assertNotSame(
            [],
            $problems,
            'The pin rule stayed silent on a .php-version naming a minor line. What ships '
            . 'when it stays silent is CI and a laptop running different patch releases '
            . 'while both look correctly configured — the state this project was already in, '
            . 'across six workflows, for months.',
        );
        self::assertStringContainsString('8.5', implode(' ', $problems));
    }

    #[Test]
    public function itRefusesAPinFileThatIsNotThere(): void
    {
        $this->delete('.nvmrc');

        $problems = $this->scanner()->pinsThatAreNotPatchExact();

        self::assertContains(
            '.nvmrc is missing',
            $problems,
            'The pin rule stayed silent on an absent .nvmrc. What ships when it stays silent '
            . 'is every workflow reading a file that is not there and falling back to the '
            . "runner's default Node, which is a version nobody chose.",
        );
    }

    #[Test]
    public function itRefusesAWorkflowThatStatesTheVersionItself(): void
    {
        // Exactly the six lines the pins replaced.
        $this->plant($this->tempDirectory, '.github/workflows/drifted.yml', <<<'YAML'
            name: drifted
            on: [push]
            jobs:
              build:
                runs-on: ubuntu-latest
                steps:
                  - uses: shivammathur/setup-php@v2
                    with:
                      php-version: '8.5'
                      php-version-file: .php-version
                  - uses: actions/setup-node@v4
                    with:
                      node-version: '22'
                      node-version-file: .nvmrc
            YAML);

        $offenders = $this->scanner()->hardcodedRuntimeVersions();

        self::assertCount(
            2,
            $offenders,
            'The rule stayed silent on a workflow stating its own runtime versions. What '
            . 'ships when it stays silent is a second source of truth beside the pin file, '
            . 'and the two diverging without anyone being wrong — which is how this '
            . 'repository ended up running three versions of the same tool. It reported: ['
            . implode(', ', $offenders) . ']',
        );
        self::assertStringContainsString("php-version: '8.5'", implode(' ', $offenders));
        self::assertStringContainsString("node-version: '22'", implode(' ', $offenders));
    }

    #[Test]
    public function itRefusesAWorkflowThatSetsUpARuntimeWithoutReadingThePin(): void
    {
        $this->plant($this->tempDirectory, '.github/workflows/unpinned.yml', <<<'YAML'
            name: unpinned
            on: [push]
            jobs:
              build:
                runs-on: ubuntu-latest
                steps:
                  - uses: shivammathur/setup-php@v2
                  - uses: actions/setup-node@v4
            YAML);

        $missing = $this->scanner()->workflowsIgnoringThePins();

        self::assertContains(
            'unpinned.yml sets up PHP without reading .php-version',
            $missing,
            'The rule stayed silent on a workflow that sets up PHP and never reads the pin. '
            . 'What ships when it stays silent is a job running whatever PHP the runner '
            . 'image happens to ship that week, on a repository that believes it is pinned.',
        );
        self::assertContains('unpinned.yml sets up Node without reading .nvmrc', $missing);
    }

    #[Test]
    public function itRefusesAPinBelowWhatComposerRequires(): void
    {
        $this->plant($this->tempDirectory, '.php-version', "8.4.3\n");

        $floor = ToolchainPinScanner::constraintFloor('>=8.5.0');

        self::assertNotNull($floor);
        self::assertFalse(
            version_compare($this->scanner()->pinned('.php-version'), $floor, '>='),
            'The rule stayed silent on a pin below the constraint the package declares. '
            . 'What ships when it stays silent is a pin that is a second opinion rather '
            . 'than a source of truth: every developer and every runner installs an '
            . 'interpreter the package itself says it cannot run on.',
        );
    }

    /**
     * A constraint the rule cannot parse must refuse, not shrug.
     *
     * Reading an unrecognised shape as satisfied is the vacuous pass this whole exercise
     * is about: nothing was compared, and the result was reported as agreement.
     */
    #[Test]
    public function itRefusesAConstraintShapeItCannotRead(): void
    {
        foreach (['^8.5', '>=8.5', '8.5.*', ''] as $constraint) {
            self::assertNull(
                ToolchainPinScanner::constraintFloor($constraint),
                'The rule claimed to have checked the pin against "' . $constraint . '", a '
                . 'constraint it does not understand. What ships when it stays silent is a '
                . 'comparison that never happened, reported as a comparison that passed.',
            );
        }

        self::assertSame('8.5.0', ToolchainPinScanner::constraintFloor('>=8.5.0'));
    }

    #[Test]
    public function itRefusesAConfigurationDeclaringAnotherPhpunitsSchema(): void
    {
        $config = $this->plant($this->tempDirectory, 'phpunit.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                     xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
                     bootstrap="bootstrap.php">
                <testsuites>
                    <testsuite name="Unit">
                        <directory>tests</directory>
                    </testsuite>
                </testsuites>
            </phpunit>
            XML);

        self::assertSame(
            '10.5',
            ToolchainPinScanner::declaredSchemaSeries($config),
            'The rule could not read the declared schema series at all, so the comparison '
            . 'it makes against the installed PHPUnit compares nothing.',
        );
        self::assertNotSame(
            '13.2',
            ToolchainPinScanner::declaredSchemaSeries($config),
            'What ships when this stays silent is an editor validating the test '
            . 'configuration against a schema a major version behind: it reports valid '
            . 'options as unknown, and says nothing at all about options that did not '
            . 'exist yet.',
        );
    }

    /**
     * The one that catches a typo in a strictness switch.
     *
     * PHPUnit does not object to an attribute it does not recognise — it simply ignores
     * it. So `failOnRisky` misspelled is not an error, it is a strictness that silently
     * never applies, on a suite that looks stricter than it is.
     */
    #[Test]
    public function itRefusesAConfigurationWithAMisspelledStrictnessAttribute(): void
    {
        $schema = dirname(__DIR__, 4) . '/vendor/phpunit/phpunit/phpunit.xsd';

        if (!is_file($schema)) {
            self::markTestSkipped('the installed PHPUnit ships no schema to validate against');
        }

        $config = $this->plant($this->tempDirectory, 'typo.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                     failOnRisk="true">
                <testsuites>
                    <testsuite name="Unit">
                        <directory>tests</directory>
                    </testsuite>
                </testsuites>
            </phpunit>
            XML);

        $problems = ToolchainPinScanner::schemaViolations($config, $schema);

        self::assertNotSame(
            [],
            $problems,
            'The schema rule accepted a configuration whose strictness attribute is '
            . 'misspelled. What ships when it stays silent is a suite that believes it '
            . 'fails on risky tests and does not: PHPUnit ignores the attribute it does '
            . 'not recognise, and nothing else in the pipeline reads this file.',
        );
        self::assertStringContainsString('failOnRisk', implode(' ', $problems));
    }

    /**
     * The same rules over a checkout that has not drifted, so every refusal above is a
     * property of the defect rather than of the rule.
     */
    #[Test]
    public function itIsSilentOnACheckoutThatIsCorrectlyPinned(): void
    {
        self::assertSame([], $this->scanner()->pinsThatAreNotPatchExact());
        self::assertSame([], $this->scanner()->hardcodedRuntimeVersions());
        self::assertSame([], $this->scanner()->workflowsIgnoringThePins());
        self::assertNotSame([], $this->scanner()->workflows(), 'the fixture workflow was not found at all');
    }

    private function scanner(): ToolchainPinScanner
    {
        return new ToolchainPinScanner($this->tempDirectory);
    }

    private function delete(string $relativePath): void
    {
        $path = $this->tempDirectory . '/' . $relativePath;

        if (is_file($path)) {
            unlink($path);
        }
    }
}
