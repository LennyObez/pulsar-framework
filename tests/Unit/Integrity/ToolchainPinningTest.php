<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use DOMDocument;
use LibXMLError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Version;
use Pulsar\Tooling\Support\JsonDocument;

use function array_map;
use function dirname;
use function glob;
use function libxml_clear_errors;
use function libxml_get_errors;
use function libxml_use_internal_errors;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function trim;
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
        foreach (['.php-version', '.nvmrc'] as $file) {
            $path = $this->root() . '/' . $file;

            self::assertFileExists($path, "$file is the single source of truth for its runtime");

            $version = trim((string) file_get_contents($path));

            self::assertMatchesRegularExpression(
                '/^\d+\.\d+\.\d+$/',
                $version,
                "$file must pin a patch-exact version: a minor line lets CI and a laptop "
                . 'run different builds while both look correctly configured.',
            );
        }
    }

    #[Test]
    public function noWorkflowHardcodesARuntimeVersion(): void
    {
        $offenders = [];

        foreach ($this->workflows() as $path => $yaml) {
            if (preg_match_all('/^\s*(php|node)-version:\s*\S+/m', $yaml, $matches) >= 1) {
                foreach ($matches[0] as $line) {
                    $offenders[] = basename($path) . ': ' . trim($line);
                }
            }
        }

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
        $missing = [];

        foreach ($this->workflows() as $path => $yaml) {
            if (str_contains($yaml, 'shivammathur/setup-php') && !str_contains($yaml, 'php-version-file')) {
                $missing[] = basename($path) . ' sets up PHP without reading .php-version';
            }

            if (str_contains($yaml, 'actions/setup-node') && !str_contains($yaml, 'node-version-file')) {
                $missing[] = basename($path) . ' sets up Node without reading .nvmrc';
            }
        }

        self::assertSame([], $missing, implode("\n  ", $missing));
    }

    /**
     * The pinned interpreter must actually satisfy what the package declares, or the
     * pin is a second opinion rather than a source of truth.
     */
    #[Test]
    public function thePinnedPhpSatisfiesTheComposerConstraint(): void
    {
        $pinned = trim((string) file_get_contents($this->root() . '/.php-version'));
        $constraint = JsonDocument::fromFile($this->root() . '/composer.json')
            ->child('require')
            ->string('php');

        self::assertSame(
            1,
            preg_match('/^>=\s*(\d+\.\d+\.\d+)$/', $constraint, $matches),
            "This check understands a `>=x.y.z` constraint; composer.json now says '$constraint'.",
        );

        $required = $matches[1] ?? '';

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
        $config = (string) file_get_contents($this->configPath());
        $series = Version::series();

        self::assertMatchesRegularExpression(
            '#schema\.phpunit\.de/' . preg_quote($series, '#') . '/phpunit\.xsd#',
            $config,
            'tools/php/phpunit.xml declares a schema for a different PHPUnit than the '
            . "$series that composer installed.",
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

        $previous = libxml_use_internal_errors(true);

        $document = new DOMDocument();
        $document->load($this->configPath());
        $valid = $document->schemaValidate($schema);

        $problems = array_map(
            static fn(LibXMLError $error): string => 'line ' . $error->line . ': ' . trim($error->message),
            libxml_get_errors(),
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($valid, "tools/php/phpunit.xml is invalid:\n  " . implode("\n  ", $problems));
    }

    private function configPath(): string
    {
        return $this->root() . '/tools/php/phpunit.xml';
    }

    /**
     * @return array<string, string>
     */
    private function workflows(): array
    {
        $files = glob($this->root() . '/.github/workflows/*.yml') ?: [];
        $yamls = [];

        foreach ($files as $file) {
            $yamls[$file] = (string) file_get_contents($file);
        }

        self::assertNotSame([], $yamls, 'no workflows found — the check would pass vacuously');

        return $yamls;
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
