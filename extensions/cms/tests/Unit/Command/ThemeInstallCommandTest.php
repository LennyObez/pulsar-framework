<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Extension\Cms\Command\ThemeInstallCommand;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function is_file;
use function json_encode;
use function mkdir;
use function random_bytes;
use function str_starts_with;
use function sys_get_temp_dir;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ThemeInstallCommand::class)]
final class ThemeInstallCommandTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            $this->removeTempFile($path);
        }

        $dirs = array_reverse($this->tempDirs);

        foreach ($dirs as $path) {
            $this->removeTempDir($path);
        }

        $this->tempFiles = [];
        $this->tempDirs = [];
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $repo = $this->createStub(ThemeRepositoryInterface::class);
        $command = new ThemeInstallCommand($repo, '/tmp/themes');

        self::assertSame('cms:theme:install', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function failsWhenPathArgumentMissing(): void
    {
        $repo = $this->createStub(ThemeRepositoryInterface::class);
        $command = new ThemeInstallCommand($repo, '/tmp/themes');

        $input = new ArrayInput('cms:theme:install', []);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Invalid->value, $exitCode);
        self::assertStringContainsString('Missing required argument', $output->errorBuffer);
    }

    #[Test]
    public function failsWhenPathIsNotADirectory(): void
    {
        $repo = $this->createStub(ThemeRepositoryInterface::class);
        $command = new ThemeInstallCommand($repo, '/tmp/themes');

        $input = new ArrayInput('cms:theme:install', ['/nonexistent/path/to/theme']);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('Not a directory', $output->errorBuffer);
    }

    #[Test]
    public function failsWhenThemeJsonMissing(): void
    {
        $dir = $this->createTempDir();

        $repo = $this->createStub(ThemeRepositoryInterface::class);
        $command = new ThemeInstallCommand($repo, '/tmp/themes');

        $input = new ArrayInput('cms:theme:install', [$dir]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('No theme.json found', $output->errorBuffer);
    }

    #[Test]
    public function failsWhenSlugAlreadyInstalled(): void
    {
        $dir = $this->createTempDir();
        $this->writeThemeJson($dir, 'my-theme', 'My Theme', '1.0.0');

        $existingTheme = $this->createStub(InstalledTheme::class);

        $repo = $this->createStub(ThemeRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn($existingTheme);

        $storageDir = $this->createTempDir();
        $command = new ThemeInstallCommand($repo, $storageDir);

        $input = new ArrayInput('cms:theme:install', [$dir]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('already installed', $output->errorBuffer);
    }

    #[Test]
    public function failsWhenManifestMissesSlug(): void
    {
        $dir = $this->createTempDir();
        $this->writeThemeJson($dir, '', 'No Slug Theme', '1.0.0');

        $repo = $this->createStub(ThemeRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn(null);

        $storageDir = $this->createTempDir();
        $command = new ThemeInstallCommand($repo, $storageDir);

        $input = new ArrayInput('cms:theme:install', [$dir]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Error->value, $exitCode);
        self::assertStringContainsString('missing the required "slug"', $output->errorBuffer);
    }

    #[Test]
    public function installsThemeSuccessfully(): void
    {
        $dir = $this->createTempDir();
        $this->writeThemeJson($dir, 'test-theme', 'Test Theme', '2.0.0');

        /** @var InstalledTheme|null $savedTheme */
        $savedTheme = null;

        $repo = $this->createMock(ThemeRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn(null);
        $repo->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (InstalledTheme $theme) use (&$savedTheme): void {
                $savedTheme = $theme;
            });

        $storageDir = $this->createTempDir();
        $command = new ThemeInstallCommand($repo, $storageDir);

        $input = new ArrayInput('cms:theme:install', [$dir]);
        $output = new BufferedOutput();

        $exitCode = $command->execute($input, $output);

        self::assertSame(ExitCode::Success->value, $exitCode);
        self::assertStringContainsString('Test Theme', $output->buffer);
        self::assertStringContainsString('test-theme', $output->buffer);

        self::assertNotNull($savedTheme);
        self::assertSame('test-theme', $savedTheme->slug);
        self::assertSame('Test Theme', $savedTheme->displayName);
        self::assertSame('2.0.0', $savedTheme->version);
        self::assertFalse($savedTheme->isActive);
        self::assertSame('cli', $savedTheme->installedBy);

        // Verify theme.json was copied to storage
        $copiedManifest = $storageDir . '/test-theme/theme.json';
        self::assertFileExists($copiedManifest);

        // Track for cleanup
        $this->tempFiles[] = $copiedManifest;
        $this->tempDirs[] = $storageDir . '/test-theme';
    }

    private function createTempDir(): string
    {
        $base = sys_get_temp_dir() . '/pulsar_test_' . bin2hex(random_bytes(8));
        mkdir($base, 0o755, true);
        $this->tempDirs[] = $base;

        return $base;
    }

    private function writeThemeJson(string $dir, string $slug, string $name, string $version): void
    {
        $data = [
            'slug' => $slug,
            'display_name' => $name,
            'version' => $version,
        ];

        $path = $dir . '/theme.json';
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        $this->tempFiles[] = $path;
    }

    /**
     * Safely remove a temp file created by this test.
     *
     * Only removes files within sys_get_temp_dir() as a safety measure.
     */
    private function removeTempFile(string $path): void
    {
        $tempDir = sys_get_temp_dir();

        if (str_starts_with($path, $tempDir) && is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Safely remove a temp directory created by this test.
     *
     * Only removes directories within sys_get_temp_dir() as a safety measure.
     */
    private function removeTempDir(string $path): void
    {
        $tempDir = sys_get_temp_dir();

        if (str_starts_with($path, $tempDir) && is_dir($path)) {
            rmdir($path);
        }
    }
}
