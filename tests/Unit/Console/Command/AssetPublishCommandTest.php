<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\AssetPublishCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;

use const DIRECTORY_SEPARATOR;

#[CoversClass(AssetPublishCommand::class)]
final class AssetPublishCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'pulsar_asset_publish_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanupDir($this->tempDir);
    }

    #[Test]
    public function commandNameAndDescriptionAreConfigured(): void
    {
        $command = new AssetPublishCommand($this->tempDir);

        self::assertSame('asset:publish', $command->name);
        self::assertNotSame('', $command->description);
    }

    #[Test]
    public function returnsErrorWhenResourcesDirMissing(): void
    {
        $command = new AssetPublishCommand($this->tempDir);
        $input = new ArrayInput('asset:publish');
        $output = new BufferedOutput();

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $result);
    }

    #[Test]
    public function copyModeCopiesFilesInsteadOfSymlinking(): void
    {
        $this->createResourceDir('css');
        file_put_contents(
            $this->tempDir . DIRECTORY_SEPARATOR . 'resources'
                . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'app.css',
            'body { color: red; }',
        );

        $command = new AssetPublishCommand($this->tempDir);
        $input = new ArrayInput('asset:publish', [], ['copy' => true]);
        $output = new BufferedOutput();

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);

        $targetCss = $this->tempDir . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css'
            . DIRECTORY_SEPARATOR . 'app.css';
        self::assertFileExists($targetCss);
        self::assertSame('body { color: red; }', file_get_contents($targetCss));
    }

    #[Test]
    public function skipsNonExistentResourceSubdirs(): void
    {
        // Only create css, not views or lang
        $this->createResourceDir('css');

        $command = new AssetPublishCommand($this->tempDir);
        $input = new ArrayInput('asset:publish', [], ['copy' => true]);
        $output = new BufferedOutput();

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);

        // views was not created, so it should not be published
        $viewsDir = $this->tempDir . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'views';
        self::assertFalse(is_dir($viewsDir), 'views should not be published');

        self::assertStringContainsString('Skipped', $output->buffer);
    }

    #[Test]
    public function skipsExistingDirsWithoutForce(): void
    {
        $this->createResourceDir('css');

        // Run once with --copy to create the target directory
        $command = new AssetPublishCommand($this->tempDir);
        $command->execute(new ArrayInput('asset:publish', [], ['copy' => true]), new BufferedOutput());

        // Run again without --force
        $output = new BufferedOutput();
        $result = $command->execute(new ArrayInput('asset:publish', [], ['copy' => true]), $output);

        self::assertSame(ExitCode::Success->value, $result);
        self::assertStringContainsString('Skipped', $output->buffer);
    }

    #[Test]
    public function forceOverwritesExistingCopiedDirs(): void
    {
        $this->createResourceDir('css');
        $cssFile = $this->tempDir . DIRECTORY_SEPARATOR . 'resources'
            . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'app.css';
        file_put_contents($cssFile, 'version-1');

        // Run once with --copy
        $command = new AssetPublishCommand($this->tempDir);
        $command->execute(new ArrayInput('asset:publish', [], ['copy' => true]), new BufferedOutput());

        // Change the source file
        file_put_contents($cssFile, 'version-2');

        // Run again with --force --copy
        $input = new ArrayInput('asset:publish', [], ['force' => true, 'copy' => true]);
        $output = new BufferedOutput();
        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);

        $targetCss = $this->tempDir . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css'
            . DIRECTORY_SEPARATOR . 'app.css';
        self::assertSame('version-2', file_get_contents($targetCss));
    }

    #[Test]
    public function createsPublicAssetsDirectoryIfMissing(): void
    {
        $this->createResourceDir('lang');

        $assetsDir = $this->tempDir . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . 'assets';
        self::assertDirectoryDoesNotExist($assetsDir);

        $command = new AssetPublishCommand($this->tempDir);
        $command->execute(new ArrayInput('asset:publish', [], ['copy' => true]), new BufferedOutput());

        self::assertDirectoryExists($assetsDir);
    }

    #[Test]
    public function publishesMultipleResourceDirs(): void
    {
        $this->createResourceDir('views');
        $this->createResourceDir('css');
        $this->createResourceDir('lang');

        file_put_contents(
            $this->tempDir . DIRECTORY_SEPARATOR . 'resources'
                . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'style.css',
            'h1 { }',
        );

        $command = new AssetPublishCommand($this->tempDir);
        $input = new ArrayInput('asset:publish', [], ['copy' => true]);
        $output = new BufferedOutput();

        $result = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $result);
        self::assertStringContainsString('Published: 3', $output->buffer);
    }

    // --- helpers ---

    private function createResourceDir(string $name): void
    {
        $dir = $this->tempDir . DIRECTORY_SEPARATOR . 'resources'
            . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
    }

    private function cleanupDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                $this->cleanupDir($full);
            } else {
                // nosemgrep: php.lang.security.unlink-use.unlink-use
                unlink($full);
            }
        }

        rmdir($path);
    }
}
