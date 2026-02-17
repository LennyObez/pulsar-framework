<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Cli;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_exists;
use function file_get_contents;
use function is_dir;

use const DIRECTORY_SEPARATOR;

/**
 * Integration tests for bin/pulsar project root isolation logic.
 *
 * Verifies that the CLI script resolves $projectRoot from getcwd()
 * and that the config path fallback only uses the framework config
 * when running from within the framework directory itself.
 */
#[CoversNothing]
final class PulsarProjectRootTest extends TestCase
{
    private string $scriptPath;

    protected function setUp(): void
    {
        $this->scriptPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'pulsar';

        if (!file_exists($this->scriptPath)) {
            self::fail('bin/pulsar not found at: ' . $this->scriptPath);
        }
    }

    #[Test]
    public function projectRootUsesGetcwdNotDirConstant(): void
    {
        // Arrange: read the script source
        $source = file_get_contents($this->scriptPath);
        self::assertIsString($source);

        // Assert: $projectRoot is assigned from getcwd(), not __DIR__
        self::assertStringContainsString(
            '$projectRoot = getcwd()',
            $source,
            'bin/pulsar must derive $projectRoot from getcwd() for symlink compatibility',
        );

        // Verify that getcwd-based vendor path comes FIRST in autoload resolution
        $cwdAutoloadPos = strpos($source, "getcwd() . '/vendor/autoload.php'");
        $dirAutoloadPos = strpos($source, "__DIR__ . '/../vendor/autoload.php'");

        self::assertIsInt($cwdAutoloadPos, 'getcwd vendor autoload path must be present');
        self::assertIsInt($dirAutoloadPos, '__DIR__ vendor autoload path must be present');
        self::assertLessThan(
            $dirAutoloadPos,
            $cwdAutoloadPos,
            'getcwd autoload must be checked before __DIR__ autoload for symlink support',
        );
    }

    #[Test]
    public function configFallbackRequiresFrameworkDirectoryMatch(): void
    {
        // Arrange: read the script source
        $source = file_get_contents($this->scriptPath);
        self::assertIsString($source);

        // Assert: the fallback logic checks realpath($projectRoot) === realpath(__DIR__ . '/..')
        // This prevents symlinked installs from accidentally using the framework config
        self::assertStringContainsString(
            "realpath(\$projectRoot) === realpath(__DIR__ . '/..')",
            $source,
            'Config fallback must verify we are in the framework directory before using framework config',
        );
    }

    #[Test]
    public function noConfigDirInExternalProjectDoesNotFallBackToFramework(): void
    {
        // Arrange: read the script source and verify the conditional structure
        $source = file_get_contents($this->scriptPath);
        self::assertIsString($source);

        // The script must NOT unconditionally fall back to __DIR__/../config.
        // It should only fall back when realpath matches the framework dir.
        // Verify the is_dir check is guarded by the realpath comparison.
        $fallbackBlock = "if (is_dir(\$frameworkConfig) && realpath(\$projectRoot) === realpath(__DIR__ . '/..'))";

        self::assertStringContainsString(
            $fallbackBlock,
            $source,
            'Framework config fallback must be guarded by both is_dir and realpath equality checks',
        );
    }

    #[Test]
    public function configPathDerivedFromProjectRoot(): void
    {
        $source = file_get_contents($this->scriptPath);
        self::assertIsString($source);

        // The config path must be derived from $projectRoot, not __DIR__
        self::assertStringContainsString(
            "\$configPath = \$projectRoot . '/config'",
            $source,
            'Config path must be derived from $projectRoot (getcwd), not __DIR__',
        );
    }
}
