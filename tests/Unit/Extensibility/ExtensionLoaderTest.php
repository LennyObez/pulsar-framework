<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\Exception\DependencyException;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ExtensionLoader;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Routing\RouterInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(ExtensionLoader::class)]
final class ExtensionLoaderTest extends TestCase
{
    private ExtensionLoader $loader;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->loader = new ExtensionLoader();
        $this->tempDir = sys_get_temp_dir() . '/pulsar-test-' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function discoverFindsManifestsInPaths(): void
    {
        // Create test extension directory
        $extDir = $this->tempDir . '/test-extension';
        mkdir($extDir);
        file_put_contents($extDir . '/pulsar.json', json_encode([
            'name' => 'test/extension',
            'version' => '1.0.0',
            'extension_class' => 'Test\\Extension',
        ]));

        $manifests = $this->loader->discover([$this->tempDir]);

        self::assertCount(1, $manifests);
        self::assertSame('test/extension', $manifests[0]->name);
    }

    #[Test]
    public function discoverIgnoresNonExistentPaths(): void
    {
        $manifests = $this->loader->discover(['/nonexistent/path']);

        self::assertSame([], $manifests);
    }

    #[Test]
    public function discoverIgnoresDirectoriesWithoutManifest(): void
    {
        $extDir = $this->tempDir . '/no-manifest';
        mkdir($extDir);

        $manifests = $this->loader->discover([$this->tempDir]);

        self::assertSame([], $manifests);
    }

    #[Test]
    public function resolveDependenciesSortsByDependencyOrder(): void
    {
        // Extension B depends on A
        $manifestA = ExtensionManifest::fromArray([
            'name' => 'ext/a',
            'version' => '1.0.0',
            'extension_class' => 'ExtA',
        ]);

        $manifestB = ExtensionManifest::fromArray([
            'name' => 'ext/b',
            'version' => '1.0.0',
            'extension_class' => 'ExtB',
            'requires' => ['ext/a' => '1.0'],
        ]);

        $sorted = $this->loader->resolveDependencies([$manifestB, $manifestA]);

        self::assertSame('ext/a', $sorted[0]->name);
        self::assertSame('ext/b', $sorted[1]->name);
    }

    #[Test]
    public function resolveDependenciesSkipsExtensionWithMissingDependency(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'name' => 'ext/a',
            'version' => '1.0.0',
            'extension_class' => 'ExtA',
            'requires' => ['ext/missing' => '1.0'],
        ]);

        // Source logs skipped extensions via error_log() — redirect to temp file to avoid risky test
        $previousLog = ini_set('error_log', tempnam(sys_get_temp_dir(), 'phpunit'));
        $sorted = $this->loader->resolveDependencies([$manifest]);
        ini_set('error_log', $previousLog !== false ? $previousLog : '');

        self::assertSame([], $sorted);
    }

    #[Test]
    public function resolveDependenciesThrowsOnCircularDependency(): void
    {
        $manifestA = ExtensionManifest::fromArray([
            'name' => 'ext/a',
            'version' => '1.0.0',
            'extension_class' => 'ExtA',
            'requires' => ['ext/b' => '1.0'],
        ]);

        $manifestB = ExtensionManifest::fromArray([
            'name' => 'ext/b',
            'version' => '1.0.0',
            'extension_class' => 'ExtB',
            'requires' => ['ext/a' => '1.0'],
        ]);

        $this->expectException(DependencyException::class);
        $this->expectExceptionMessage('Circular dependency');

        $this->loader->resolveDependencies([$manifestA, $manifestB]);
    }

    #[Test]
    public function resolveDependenciesHandlesNoDependencies(): void
    {
        $manifestA = ExtensionManifest::fromArray([
            'name' => 'ext/a',
            'version' => '1.0.0',
            'extension_class' => 'ExtA',
        ]);

        $manifestB = ExtensionManifest::fromArray([
            'name' => 'ext/b',
            'version' => '1.0.0',
            'extension_class' => 'ExtB',
        ]);

        $sorted = $this->loader->resolveDependencies([$manifestA, $manifestB]);

        self::assertCount(2, $sorted);
    }

    #[Test]
    public function discoverLoadsIndividualExtensionPathWithManifest(): void
    {
        // Create an extension directory that itself contains pulsar.json
        $extDir = $this->tempDir . '/my-ext';
        mkdir($extDir);
        file_put_contents($extDir . '/pulsar.json', json_encode([
            'name' => 'test/individual',
            'version' => '1.0.0',
            'extension_class' => 'Test\\IndividualExtension',
        ]));

        // Pass the individual extension directory itself, not its parent
        $manifests = $this->loader->discover([$extDir]);

        self::assertCount(1, $manifests);
        self::assertSame('test/individual', $manifests[0]->name);
    }

    #[Test]
    public function discoverDeduplicatesSameExtensionFromMultiplePaths(): void
    {
        // Create extension in parent directory scan
        $extDir = $this->tempDir . '/ext-a';
        mkdir($extDir);
        file_put_contents($extDir . '/pulsar.json', json_encode([
            'name' => 'test/dedup',
            'version' => '1.0.0',
            'extension_class' => 'Test\\DedupExtension',
        ]));

        // Pass both the parent dir and the individual dir
        $manifests = $this->loader->discover([$this->tempDir, $extDir]);

        self::assertCount(1, $manifests);
        self::assertSame('test/dedup', $manifests[0]->name);
    }

    #[Test]
    public function discoverMixesParentAndIndividualPaths(): void
    {
        // Extension in a parent directory scan
        $parentDir = $this->tempDir . '/framework-exts';
        mkdir($parentDir . '/ext-a', 0o755, true);
        file_put_contents($parentDir . '/ext-a/pulsar.json', json_encode([
            'name' => 'framework/ext-a',
            'version' => '1.0.0',
            'extension_class' => 'Framework\\ExtA',
        ]));

        // Individual app extension directory
        $appExtDir = $this->tempDir . '/app-ext';
        mkdir($appExtDir);
        file_put_contents($appExtDir . '/pulsar.json', json_encode([
            'name' => 'app/custom',
            'version' => '1.0.0',
            'extension_class' => 'App\\CustomExtension',
        ]));

        $manifests = $this->loader->discover([$parentDir, $appExtDir]);

        self::assertCount(2, $manifests);
        $names = array_map(fn(ExtensionManifest $m) => $m->name, $manifests);
        self::assertContains('framework/ext-a', $names);
        self::assertContains('app/custom', $names);
    }

    #[Test]
    public function instantiateCreatesExtensionInstance(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'name' => 'test/stub',
            'version' => '1.0.0',
            'extension_class' => StubExtension::class,
        ]);

        $extension = $this->loader->instantiate($manifest);

        self::assertInstanceOf(ExtensionInterface::class, $extension);
        self::assertSame('test/stub', $extension->name());
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}

/**
 * Stub extension for testing instantiation.
 */
class StubExtension implements ExtensionInterface
{
    public function name(): string
    {
        return 'test/stub';
    }

    public function register(ContainerInterface $container): void {}

    public function boot(ContainerInterface $container, RouterInterface $router): void {}

    public function providers(): array
    {
        return [];
    }
}
