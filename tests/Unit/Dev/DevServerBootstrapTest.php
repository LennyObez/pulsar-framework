<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pulsar\Dev\DevServerBootstrap;
use Pulsar\Dev\DevServerConfig;

use function dirname;

#[CoversClass(DevServerBootstrap::class)]
final class DevServerBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        DevServerBootstrap::resetCache();
    }

    public function testMimeTypesIncludesAllCommonWebFormats(): void
    {
        $mimeTypes = DevServerBootstrap::getMimeTypes();

        self::assertSame('text/css; charset=UTF-8', $mimeTypes['css']);
        self::assertSame('application/javascript; charset=UTF-8', $mimeTypes['js']);
        self::assertSame('application/json; charset=UTF-8', $mimeTypes['json']);
        self::assertSame('text/html; charset=UTF-8', $mimeTypes['html']);
        self::assertSame('image/svg+xml', $mimeTypes['svg']);
        self::assertSame('image/png', $mimeTypes['png']);
        self::assertSame('image/jpeg', $mimeTypes['jpg']);
        self::assertSame('image/jpeg', $mimeTypes['jpeg']);
        self::assertSame('image/gif', $mimeTypes['gif']);
        self::assertSame('image/x-icon', $mimeTypes['ico']);
        self::assertSame('font/woff', $mimeTypes['woff']);
        self::assertSame('font/woff2', $mimeTypes['woff2']);
        self::assertSame('font/ttf', $mimeTypes['ttf']);
        self::assertSame('application/json; charset=UTF-8', $mimeTypes['map']);
    }

    public function testMimeTypesDoNotContainEmptyValues(): void
    {
        foreach (DevServerBootstrap::getMimeTypes() as $ext => $mime) {
            self::assertNotSame('', $mime, "MIME type for .$ext is empty");
            self::assertIsString($ext);
        }
    }

    public function testServeStaticAssetReturnsFalseForNonMatchingPath(): void
    {
        $config = new DevServerConfig(extensionName: 'test');
        $projectRoot = dirname(__DIR__, 3);

        $result = DevServerBootstrap::serveStaticAsset($projectRoot, '/api/users', $config);

        self::assertFalse($result);
    }

    public function testServeStaticAssetReturnsFalseForNonExistentUiFile(): void
    {
        $config = new DevServerConfig(extensionName: 'test');
        $projectRoot = dirname(__DIR__, 3);

        $result = DevServerBootstrap::serveStaticAsset($projectRoot, '/ui/nonexistent/file.css', $config);

        self::assertFalse($result);
    }

    public function testServeStaticAssetReturnsFalseForNonMatchingExtensionPrefix(): void
    {
        $config = new DevServerConfig(
            extensionName: 'test',
            assetPrefixes: [
                '/admin/assets/' => ['extensions/admin/frontend/styles'],
            ],
        );
        $projectRoot = dirname(__DIR__, 3);

        $result = DevServerBootstrap::serveStaticAsset($projectRoot, '/studio/assets/foo.css', $config);

        self::assertFalse($result);
    }

    public function testResetCacheClearsUiDirCache(): void
    {
        // Access serveStaticAsset to populate the UI dir cache
        $config = new DevServerConfig(extensionName: 'test');
        $projectRoot = dirname(__DIR__, 3);
        DevServerBootstrap::serveStaticAsset($projectRoot, '/ui/nonexistent.css', $config);

        // Reset should not throw
        DevServerBootstrap::resetCache();

        // Re-access should work (no stale cache)
        $result = DevServerBootstrap::serveStaticAsset($projectRoot, '/api/test', $config);
        self::assertFalse($result);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideStaticAssetPaths(): iterable
    {
        yield 'ui css path' => ['/ui/css/pulsar-ui.css', true];
        yield 'ui js path' => ['/ui/js/accordion.js', true];
        yield 'non-ui path' => ['/api/data', false];
        yield 'root path' => ['/', false];
        yield 'favicon' => ['/favicon.ico', false];
    }

    #[DataProvider('provideStaticAssetPaths')]
    public function testServeStaticAssetRecognizesUiPaths(string $path, bool $expectedMatch): void
    {
        $config = new DevServerConfig(extensionName: 'test');
        $projectRoot = dirname(__DIR__, 3);

        // We can only verify non-matching paths without output buffering
        // since matching paths call header() and readfile().
        if (!$expectedMatch) {
            $result = DevServerBootstrap::serveStaticAsset($projectRoot, $path, $config);
            self::assertFalse($result);
        } else {
            // For matching paths, verify the file exists on disk
            $filePath = $projectRoot . '/resources/ui/' . substr($path, 4);
            self::assertFileExists($filePath, "Expected static asset to exist: $filePath");
        }
    }
}
