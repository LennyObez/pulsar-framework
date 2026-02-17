<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Internal\Themes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Cms\Internal\Themes\ThemeDiscoveryService;
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

#[CoversClass(ThemeDiscoveryService::class)]
final class ThemeDiscoveryServiceTest extends TestCase
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
    public function returnsEmptyWhenNoThemesExist(): void
    {
        $basePath = $this->createTempDir();

        $repo = $this->createStub(ThemeRepositoryInterface::class);
        $service = new ThemeDiscoveryService($repo, new NullLogger(), $basePath);

        $result = $service->discover();

        self::assertSame([], $result);
    }

    #[Test]
    public function discoversNewThemeFromDisk(): void
    {
        $basePath = $this->createTempDir();
        $themeDir = $basePath . '/alpine';
        mkdir($themeDir, 0o755, true);
        $this->tempDirs[] = $themeDir;

        $this->writeThemeJson($themeDir, 'alpine', 'Alpine Theme', '1.2.0');

        /** @var list<InstalledTheme> $savedThemes */
        $savedThemes = [];

        $repo = $this->createMock(ThemeRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn(null);
        $repo->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (InstalledTheme $theme) use (&$savedThemes): void {
                $savedThemes[] = $theme;
            });

        $service = new ThemeDiscoveryService($repo, new NullLogger(), $basePath);

        $result = $service->discover();

        self::assertCount(1, $result);
        self::assertSame('alpine', $result[0]->slug);
        self::assertSame('Alpine Theme', $result[0]->displayName);
        self::assertSame('1.2.0', $result[0]->version);
        self::assertFalse($result[0]->isActive);
        self::assertSame('auto-discovery', $result[0]->installedBy);
    }

    #[Test]
    public function skipsAlreadyRegisteredThemes(): void
    {
        $basePath = $this->createTempDir();
        $themeDir = $basePath . '/existing';
        mkdir($themeDir, 0o755, true);
        $this->tempDirs[] = $themeDir;

        $this->writeThemeJson($themeDir, 'existing', 'Existing Theme', '1.0.0');

        $existingTheme = $this->createStub(InstalledTheme::class);

        $repo = $this->createMock(ThemeRepositoryInterface::class);
        $repo->method('findBySlug')->with('existing')->willReturn($existingTheme);
        $repo->expects(self::never())->method('save');

        $service = new ThemeDiscoveryService($repo, new NullLogger(), $basePath);

        $result = $service->discover();

        self::assertSame([], $result);
    }

    #[Test]
    public function discoversMultipleThemes(): void
    {
        $basePath = $this->createTempDir();

        $dir1 = $basePath . '/theme-a';
        mkdir($dir1, 0o755, true);
        $this->tempDirs[] = $dir1;
        $this->writeThemeJson($dir1, 'theme-a', 'Theme A', '1.0.0');

        $dir2 = $basePath . '/theme-b';
        mkdir($dir2, 0o755, true);
        $this->tempDirs[] = $dir2;
        $this->writeThemeJson($dir2, 'theme-b', 'Theme B', '2.0.0');

        $repo = $this->createMock(ThemeRepositoryInterface::class);
        $repo->method('findBySlug')->willReturn(null);
        $repo->expects(self::exactly(2))->method('save');

        $service = new ThemeDiscoveryService($repo, new NullLogger(), $basePath);

        $result = $service->discover();

        self::assertCount(2, $result);

        $slugs = [$result[0]->slug, $result[1]->slug];
        sort($slugs);
        self::assertSame(['theme-a', 'theme-b'], $slugs);
    }

    #[Test]
    public function skipsManifestWithInvalidJson(): void
    {
        $basePath = $this->createTempDir();
        $themeDir = $basePath . '/broken';
        mkdir($themeDir, 0o755, true);
        $this->tempDirs[] = $themeDir;

        $manifestPath = $themeDir . '/theme.json';
        file_put_contents($manifestPath, '{ invalid json }}}');
        $this->tempFiles[] = $manifestPath;

        $repo = $this->createMock(ThemeRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $service = new ThemeDiscoveryService($repo, new NullLogger(), $basePath);

        $result = $service->discover();

        self::assertSame([], $result);
    }

    #[Test]
    public function skipsManifestWithMissingSlug(): void
    {
        $basePath = $this->createTempDir();
        $themeDir = $basePath . '/no-slug';
        mkdir($themeDir, 0o755, true);
        $this->tempDirs[] = $themeDir;

        // Write a theme.json with empty slug
        $this->writeThemeJson($themeDir, '', 'No Slug', '1.0.0');

        $repo = $this->createMock(ThemeRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $service = new ThemeDiscoveryService($repo, new NullLogger(), $basePath);

        $result = $service->discover();

        self::assertSame([], $result);
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
     */
    private function removeTempDir(string $path): void
    {
        $tempDir = sys_get_temp_dir();

        if (str_starts_with($path, $tempDir) && is_dir($path)) {
            rmdir($path);
        }
    }
}
