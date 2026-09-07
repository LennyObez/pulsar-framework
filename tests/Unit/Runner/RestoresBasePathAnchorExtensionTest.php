<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runner;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tests\Runner\RestoresBasePathAnchorExtension;
use Pulsar\Tests\Unit\Tooling\Support\PlantsDefectsForGates;

use function sprintf;
use function str_replace;

use const PHP_BINARY;

/**
 * Watches the base-path anchor leak, and watches the extension stop it.
 *
 * The defect this guards against is a suite that lies about which tests pass.
 * `tools/php/bootstrap.php` anchors PULSAR_BASE_PATH at the repository root for
 * the whole run; one test clearing it and not putting it back left the process
 * unanchored, and the next `Kernel::boot()` claimed the variable for whatever
 * config directory it happened to be given. Nine tests in a different suite then
 * failed on a path none of them chose, and every file involved passed in
 * isolation. A leak with that shape cannot be caught by testing any one of the
 * files it runs through.
 *
 * So it is reproduced whole, in a child PHPUnit process, over two planted test
 * classes: one that clears the variable the way the real one did, and one that
 * asserts the anchor is still where the run started. The pair is run twice
 * against the same files — once with the extension registered and once without —
 * because a check that only ever sees the fixed configuration cannot distinguish
 * the fix from the fixture.
 *
 * The child is a real PHPUnit run (`vendor/phpunit/phpunit/phpunit`), not an
 * in-process simulation of one: the property being verified is WHEN PHPUnit emits
 * `PreparationStarted` relative to `setUp()` and `tearDown()` — PHPUnit's own
 * behaviour, which this repository does not get to assert about itself.
 */
#[CoversClass(RestoresBasePathAnchorExtension::class)]
final class RestoresBasePathAnchorExtensionTest extends TestCase
{
    use PlantsDefectsForGates;

    protected function tearDown(): void
    {
        $this->assertNothingWasLeftBehind();
    }

    #[Test]
    public function aTestThatClearsTheAnchorTakesTheNextTestDownWithIt(): void
    {
        $tree = $this->plantTree('base-path-leak');
        $this->plantThePair($tree);

        [$status, $stdout, $stderr] = $this->runCommand([
            PHP_BINARY,
            $this->repositoryRoot() . '/vendor/phpunit/phpunit/phpunit',
            '-c',
            $this->plantConfiguration($tree, 'unguarded.xml', withExtension: false),
        ]);

        self::assertNotSame(
            0,
            $status,
            "A test cleared PULSAR_BASE_PATH and the test after it still saw the run's anchor,\n"
            . "so nothing here reproduced the leak and the pass below proves nothing.\n\n"
            . $stdout . $stderr,
        );
        self::assertStringContainsString(
            'the anchor this run started with is gone',
            $stdout . $stderr,
            'the child failed for some reason other than the leak: ' . $stdout . $stderr,
        );
    }

    #[Test]
    public function theExtensionPutsTheAnchorBackBeforeTheNextTestPrepares(): void
    {
        $tree = $this->plantTree('base-path-restored');
        $this->plantThePair($tree);

        [$status, $stdout, $stderr] = $this->runCommand([
            PHP_BINARY,
            $this->repositoryRoot() . '/vendor/phpunit/phpunit/phpunit',
            '-c',
            $this->plantConfiguration($tree, 'guarded.xml', withExtension: true),
        ]);

        self::assertSame(
            0,
            $status,
            "With the extension registered, a test that clears PULSAR_BASE_PATH must not be able\n"
            . "to decide where the next test resolves its paths.\n\n" . $stdout . $stderr,
        );
    }

    /**
     * The leak and the observation, as two test classes the child runs in order.
     *
     * They are written at runtime into a directory outside every scan path, for the
     * reason {@see PlantsDefectsForGates} gives: a committed fixture that clears a
     * process global would be a fixture the real suite runs.
     */
    private function plantThePair(string $tree): void
    {
        $this->plantFile($tree, 'ClearsTheAnchorTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            final class ClearsTheAnchorTest extends PHPUnit\Framework\TestCase
            {
                protected function tearDown(): void
                {
                    // Exactly what EnvHelperTest::tearDown() used to do: clear a variable
                    // the bootstrap owns, on the way out, and stop there.
                    putenv('PULSAR_BASE_PATH');
                }

                public function testItClearsTheAnchorOnTheWayOut(): void
                {
                    self::assertTrue(true);
                }
            }
            PHP);

        $this->plantFile($tree, 'ObservesTheAnchorTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            final class ObservesTheAnchorTest extends PHPUnit\Framework\TestCase
            {
                public function testTheAnchorIsStillWhereTheRunStartedIt(): void
                {
                    self::assertNotFalse(
                        getenv('PULSAR_BASE_PATH'),
                        'the anchor this run started with is gone: the previous test cleared it and '
                        . 'nothing put it back, so every path helper from here on resolves against '
                        . 'the process CWD or against whatever the next kernel boot decides',
                    );
                }
            }
            PHP);
    }

    /**
     * A child configuration that runs the pair in order, with or without the extension.
     *
     * Absolute paths throughout: PHPUnit resolves a configuration's relative paths
     * against the configuration file, which lives in the planted tree and not in the
     * repository.
     */
    private function plantConfiguration(string $tree, string $name, bool $withExtension): string
    {
        $root = str_replace('\\', '/', $this->repositoryRoot());
        $planted = str_replace('\\', '/', $tree);

        $extensions = $withExtension
            ? '<extensions><bootstrap class="' . RestoresBasePathAnchorExtension::class . '"/></extensions>'
            : '';

        return $this->plantFile($tree, $name, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<phpunit bootstrap="%s/tools/php/bootstrap.php" cacheDirectory="%s/.cache"'
            . ' colors="false" executionOrder="default">' . "\n"
            . '    <testsuites><testsuite name="Planted">' . "\n"
            . '        <file>%s/ClearsTheAnchorTest.php</file>' . "\n"
            . '        <file>%s/ObservesTheAnchorTest.php</file>' . "\n"
            . '    </testsuite></testsuites>' . "\n"
            . '    %s' . "\n"
            . '</phpunit>' . "\n",
            $root,
            $planted,
            $planted,
            $planted,
            $extensions,
        ));
    }
}
