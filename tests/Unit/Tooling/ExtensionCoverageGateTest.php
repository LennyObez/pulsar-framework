<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Tooling\Support\PlantsDefectsForGates;

/**
 * Plants what `composer extension:coverage` exists to refuse, and observes the refusal.
 *
 * scripts/check_extension_coverage.php answers one question: is every extension with a
 * src/ directory named in the configurations that decide what gets analysed? An
 * extension missing from tools/php/phpstan.neon is not analysed by PHPStan at all, and
 * nothing anywhere says so — `composer phpstan` reports success over the code it never
 * read, which is the whole finding this work exists to close, one level up.
 *
 * The gate also had the failure mode inside itself. Its discovery walk ended with:
 *
 *     if ($extensions === []) { echo "No extensions found."; exit(0); }
 *
 * A walk that found nothing reported that every extension was covered. Pointed at the
 * wrong directory, or run after a layout change, it printed success. That branch now
 * refuses, and {@see itRefusesADiscoveryWalkThatFoundNothing} is why it cannot go back.
 *
 * `--root=` was added for these fixtures and its reason is recorded in the script.
 */
#[GuardsGate(gate: 'composer extension:coverage', plants: 'an extension with a src/ directory absent from phpstan.neon, and a discovery walk that found no extension at all')]
final class ExtensionCoverageGateTest extends TestCase
{
    use PlantsDefectsForGates;

    private const string SCRIPT = 'scripts/check_extension_coverage.php';

    protected function tearDown(): void
    {
        $this->assertNothingWasLeftBehind();
    }

    #[Test]
    public function itRefusesAnExtensionMissingFromThePhpStanPaths(): void
    {
        $root = $this->plantRepository(inPhpStan: false, inPsalm: true, inComposer: true);

        [$status, $stdout] = $this->runGate([self::SCRIPT, '--root=' . $root]);

        self::assertSame(
            1,
            $status,
            "composer extension:coverage accepted an extension with a src/ directory that\n"
            . "tools/php/phpstan.neon does not list. Had it stayed silent, that extension would sit\n"
            . "outside PHPStan entirely: composer phpstan would keep reporting success at level max\n"
            . "over source it never opened, and the first anyone would know is a runtime type error\n"
            . 'in production.',
        );
        self::assertStringContainsString('PHPStan missing extensions', $stdout);
        self::assertStringContainsString('extensions/widget/src', $stdout);
    }

    #[Test]
    public function itRefusesAnExtensionMissingFromThePsalmProjectFiles(): void
    {
        $root = $this->plantRepository(inPhpStan: true, inPsalm: false, inComposer: true);

        [$status, $stdout] = $this->runGate([self::SCRIPT, '--root=' . $root]);

        self::assertSame(
            1,
            $status,
            'composer extension:coverage accepted an extension that tools/php/psalm.xml does not list, '
            . 'so errorLevel="1" and the dead-code detection would silently not apply to it.',
        );
        self::assertStringContainsString('Psalm missing extensions', $stdout);
    }

    /**
     * The audited shape, in this gate's own terms: a scan that reached nothing must
     * not be reported as a clean scan.
     */
    #[Test]
    public function itRefusesADiscoveryWalkThatFoundNothing(): void
    {
        $root = $this->plantRepository(inPhpStan: true, inPsalm: true, inComposer: true, withExtension: false);

        [$status, , $stderr] = $this->runGate([self::SCRIPT, '--root=' . $root]);

        self::assertSame(
            1,
            $status,
            "the gate reported success over an extensions/ directory containing nothing at all.\n"
            . "That is the branch the audit describes: a check that reached nothing, read as health.\n"
            . 'Every extension in the repository would have been "covered" by not being looked at.',
        );
        self::assertStringContainsString('This is not a pass', $stderr);
    }

    #[Test]
    public function itAcceptsAnExtensionEveryConfigurationNames(): void
    {
        $root = $this->plantRepository(inPhpStan: true, inPsalm: true, inComposer: true);

        [$status, $stdout] = $this->runGate([self::SCRIPT, '--root=' . $root]);

        self::assertSame(
            0,
            $status,
            "the gate refuses an extension that every configuration lists, so its refusals above say\n"
            . 'nothing about coverage — it simply fails on whatever it is given.' . $stdout,
        );
        self::assertStringContainsString('All 1 extensions covered', $stdout);
    }

    /**
     * Exit 2 rather than 1, so "invoked wrongly and measured nothing" cannot be read
     * by a CI step as "measured something acceptable".
     */
    #[Test]
    public function itSeparatesAWrongInvocationFromAFailedCheck(): void
    {
        [$unknown, , $unknownStderr] = $this->runGate([self::SCRIPT, '--roots=/tmp']);

        self::assertSame(2, $unknown);
        self::assertStringContainsString('Unknown option: --roots=/tmp', $unknownStderr);

        $bare = $this->plantTree('extension-coverage-bare');

        [$absent, , $absentStderr] = $this->runGate([self::SCRIPT, '--root=' . $bare]);

        self::assertSame(2, $absent);
        self::assertStringContainsString('No extensions/ directory', $absentStderr);
    }

    /**
     * A miniature repository: one extension with a src/ directory, and the three
     * configuration files the gate reads, each either naming it or not.
     */
    private function plantRepository(
        bool $inPhpStan,
        bool $inPsalm,
        bool $inComposer,
        bool $withExtension = true,
    ): string {
        $root = $this->plantTree('extension-coverage');
        $path = 'extensions/widget/src';

        if ($withExtension) {
            $this->plantFile(
                $root,
                $path . '/Widget.php',
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixture;\n\nfinal class Widget {}\n",
            );
        } else {
            // The directory exists and holds no extension: the walk runs and finds
            // nothing, which is precisely the case that used to print success.
            $this->plantFile($root, 'extensions/.keep', "\n");
        }

        $this->plantFile(
            $root,
            'tools/php/phpstan.neon',
            "parameters:\n    level: max\n    paths:\n" . ($inPhpStan ? '        - ../../' . $path . "\n" : ''),
        );
        $this->plantFile(
            $root,
            'tools/php/psalm.xml',
            "<?xml version=\"1.0\"?>\n<psalm>\n    <projectFiles>\n"
            . ($inPsalm ? '        <directory name="../../' . $path . '"/>' . "\n" : '')
            . "    </projectFiles>\n</psalm>\n",
        );
        $this->plantFile(
            $root,
            'composer.json',
            "{\n    \"autoload\": {\n        \"psr-4\": {\n"
            . ($inComposer ? '            "Fixture\\\\": "' . $path . '/"' . "\n" : '')
            . "        }\n    }\n}\n",
        );

        return $root;
    }
}
