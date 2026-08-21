<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use DOMDocument;
use LibXMLError;

use function array_map;
use function basename;
use function file_get_contents;
use function glob;
use function is_file;
use function libxml_clear_errors;
use function libxml_get_errors;
use function libxml_use_internal_errors;
use function preg_match;
use function preg_match_all;
use function str_contains;
use function trim;

/**
 * The rules behind ToolchainPinningTest, pointed at a root rather than at this one.
 *
 * The pins exist because there were once three answers to "which PHP does this project
 * run": six workflows each writing `php-version: '8.5'`, a minor line the runner
 * resolved however it liked, and Node appearing as '20' in one workflow and '22' in
 * another. Pin files fix that only while the workflows actually read them, which is what
 * these rules hold in place — and holding it in place is worth nothing unless somebody
 * has watched a rule refuse a workflow that stopped reading them.
 *
 * Every method takes what it reads, so ToolchainPinningRefusesTest can assemble a
 * checkout that has drifted in each of those ways and observe the refusal.
 */
final readonly class ToolchainPinScanner
{
    /** The two pin files, each the single source of truth for its runtime. */
    public const array PIN_FILES = ['.php-version', '.nvmrc'];

    public function __construct(private string $root) {}

    /**
     * Pin files that are missing, or that name a line rather than a version.
     *
     * A minor line lets CI and a laptop run different builds while both look correctly
     * configured, which is the failure the pins were introduced to end.
     *
     * @return list<string>
     */
    public function pinsThatAreNotPatchExact(): array
    {
        $problems = [];

        foreach (self::PIN_FILES as $file) {
            $path = $this->root . '/' . $file;

            if (!is_file($path)) {
                $problems[] = $file . ' is missing';

                continue;
            }

            $version = trim((string) file_get_contents($path));

            if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
                $problems[] = $file . ' pins "' . $version . '", which is not patch-exact';
            }
        }

        return $problems;
    }

    /**
     * Workflow lines that state a runtime version instead of reading the pin file.
     *
     * @return list<string>
     */
    public function hardcodedRuntimeVersions(): array
    {
        $offenders = [];

        foreach ($this->workflows() as $path => $yaml) {
            if (preg_match_all('/^\s*(php|node)-version:\s*\S+/m', $yaml, $matches) >= 1) {
                foreach ($matches[0] as $line) {
                    $offenders[] = basename($path) . ': ' . trim($line);
                }
            }
        }

        return $offenders;
    }

    /**
     * Workflows that set up a runtime without reading its pin file.
     *
     * @return list<string>
     */
    public function workflowsIgnoringThePins(): array
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

        return $missing;
    }

    /**
     * The lowest version a `>=x.y.z` constraint admits, or null if it is not that shape.
     *
     * Null is a refusal, not a shrug: a constraint this cannot read is one the pin has
     * not been checked against, and reporting it as satisfied would be the vacuous pass
     * the whole exercise is about.
     */
    public static function constraintFloor(string $constraint): ?string
    {
        return preg_match('/^>=\s*(\d+\.\d+\.\d+)$/', $constraint, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * The PHPUnit series a test configuration declares its schema for, if any.
     */
    public static function declaredSchemaSeries(string $configPath): ?string
    {
        $config = (string) file_get_contents($configPath);

        return preg_match('#schema\.phpunit\.de/([0-9.]+)/phpunit\.xsd#', $config, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * What the schema PHPUnit itself ships says about this configuration.
     *
     * The version check alone only proves two numbers agree. This is what catches a
     * misspelled attribute: a strictness option with a typo in its name is not an error
     * to PHPUnit, it is simply an attribute it does not recognise, so the strictness
     * silently never applies and the suite looks stricter than it is.
     *
     * @return list<string>
     */
    public static function schemaViolations(string $configPath, string $schemaPath): array
    {
        $previous = libxml_use_internal_errors(true);

        $document = new DOMDocument();
        $document->load($configPath);
        $valid = $document->schemaValidate($schemaPath);

        $problems = array_map(
            static fn(LibXMLError $error): string => 'line ' . $error->line . ': ' . trim($error->message),
            libxml_get_errors(),
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $valid && $problems === [] ? [] : $problems;
    }

    public function pinned(string $file): string
    {
        return trim((string) file_get_contents($this->root . '/' . $file));
    }

    /**
     * @return array<string, string> path => contents
     */
    public function workflows(): array
    {
        $files = glob($this->root . '/.github/workflows/*.yml') ?: [];
        $yamls = [];

        foreach ($files as $file) {
            $yamls[$file] = (string) file_get_contents($file);
        }

        return $yamls;
    }
}
