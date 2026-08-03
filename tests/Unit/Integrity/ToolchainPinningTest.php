<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tooling\Support\JsonDocument;

use function dirname;
use function glob;
use function preg_match;
use function preg_match_all;
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
