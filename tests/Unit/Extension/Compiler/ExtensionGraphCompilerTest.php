<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Compiler\ExtensionGraphCompiler;
use Pulsar\Extensibility\Exception\DependencyException;
use Pulsar\Extensibility\ExtensionManifest;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function scandir;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(ExtensionGraphCompiler::class)]
final class ExtensionGraphCompilerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_graph_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function circularDependencyThrowsDependencyException(): void
    {
        $pathA = $this->createExtensionDir('ext-a');
        $pathB = $this->createExtensionDir('ext-b');

        $manifests = [
            ExtensionManifest::fromArray([
                'name' => 'vendor/ext-a',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\ExtA\\Extension',
                'requires' => ['vendor/ext-b' => '>=1.0.0'],
            ], $pathA),
            ExtensionManifest::fromArray([
                'name' => 'vendor/ext-b',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\ExtB\\Extension',
                'requires' => ['vendor/ext-a' => '>=1.0.0'],
            ], $pathB),
        ];

        $compiler = new ExtensionGraphCompiler();

        $this->expectException(DependencyException::class);
        $this->expectExceptionMessage('Circular dependency');

        $compiler->compile($manifests);
    }

    #[Test]
    public function missingDependencyThrowsDependencyException(): void
    {
        $pathA = $this->createExtensionDir('ext-a');

        $manifests = [
            ExtensionManifest::fromArray([
                'name' => 'vendor/ext-a',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\ExtA\\Extension',
                'requires' => ['vendor/ext-missing' => '>=1.0.0'],
            ], $pathA),
        ];

        $compiler = new ExtensionGraphCompiler();

        $this->expectException(DependencyException::class);
        $this->expectExceptionMessage('vendor/ext-missing');

        $compiler->compile($manifests);
    }

    #[Test]
    public function deterministicOrderingSameInputSameOutput(): void
    {
        $pathA = $this->createExtensionDir('ext-a');
        $pathB = $this->createExtensionDir('ext-b');
        $pathC = $this->createExtensionDir('ext-c');

        $manifests = [
            ExtensionManifest::fromArray([
                'name' => 'vendor/ext-c',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\ExtC\\Extension',
            ], $pathC),
            ExtensionManifest::fromArray([
                'name' => 'vendor/ext-a',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\ExtA\\Extension',
            ], $pathA),
            ExtensionManifest::fromArray([
                'name' => 'vendor/ext-b',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\ExtB\\Extension',
            ], $pathB),
        ];

        $compiler = new ExtensionGraphCompiler();

        $result1 = $compiler->compile($manifests);
        $result2 = $compiler->compile($manifests);

        self::assertSame($result1->toArray(), $result2->toArray());
        self::assertSame($result1->totalHash, $result2->totalHash);
    }

    #[Test]
    public function alphabeticalTieBreakingForNoDependencyEdge(): void
    {
        $pathA = $this->createExtensionDir('alpha');
        $pathB = $this->createExtensionDir('beta');
        $pathC = $this->createExtensionDir('gamma');

        $manifests = [
            ExtensionManifest::fromArray([
                'name' => 'vendor/gamma',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\Gamma\\Extension',
            ], $pathC),
            ExtensionManifest::fromArray([
                'name' => 'vendor/alpha',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\Alpha\\Extension',
            ], $pathA),
            ExtensionManifest::fromArray([
                'name' => 'vendor/beta',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\Beta\\Extension',
            ], $pathB),
        ];

        $compiler = new ExtensionGraphCompiler();
        $result = $compiler->compile($manifests);

        $names = array_map(
            static fn($entry) => $entry->name,
            $result->extensions,
        );

        self::assertSame(['vendor/alpha', 'vendor/beta', 'vendor/gamma'], $names);
    }

    #[Test]
    public function disabledExtensionsAreMarkedAsDisabled(): void
    {
        $pathA = $this->createExtensionDir('ext-a');
        $pathB = $this->createExtensionDir('ext-b');

        $manifests = [
            ExtensionManifest::fromArray([
                'name' => 'vendor/ext-a',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\ExtA\\Extension',
            ], $pathA),
            ExtensionManifest::fromArray([
                'name' => 'vendor/ext-b',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\ExtB\\Extension',
            ], $pathB),
        ];

        $compiler = new ExtensionGraphCompiler();
        $result = $compiler->compile($manifests, ['vendor/ext-b']);

        $entriesByName = [];

        foreach ($result->extensions as $entry) {
            $entriesByName[$entry->name] = $entry;
        }

        self::assertTrue($entriesByName['vendor/ext-a']->enabled);
        self::assertFalse($entriesByName['vendor/ext-b']->enabled);
    }

    #[Test]
    public function configAndCodeHashesAreComputed(): void
    {
        $pathA = $this->createExtensionDir('ext-a');

        // Create config and source files
        $configDir = $pathA . DIRECTORY_SEPARATOR . 'config';
        mkdir($configDir, 0o750, true);
        file_put_contents($configDir . DIRECTORY_SEPARATOR . 'settings.php', '<?php return ["key" => "value"];');

        $srcDir = $pathA . DIRECTORY_SEPARATOR . 'src';
        mkdir($srcDir, 0o750, true);
        file_put_contents($srcDir . DIRECTORY_SEPARATOR . 'Extension.php', '<?php class Extension {}');

        $manifests = [
            ExtensionManifest::fromArray([
                'name' => 'vendor/ext-a',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\ExtA\\Extension',
            ], $pathA),
        ];

        $compiler = new ExtensionGraphCompiler();
        $result = $compiler->compile($manifests);

        self::assertArrayHasKey('vendor/ext-a', $result->configHashes);
        self::assertArrayHasKey('vendor/ext-a', $result->codeHashes);
        self::assertNotEmpty($result->configHashes['vendor/ext-a']);
        self::assertNotEmpty($result->codeHashes['vendor/ext-a']);
    }

    #[Test]
    public function emptyExtensionListProducesEmptyManifest(): void
    {
        $compiler = new ExtensionGraphCompiler();
        $result = $compiler->compile([]);

        self::assertSame([], $result->extensions);
        self::assertSame([], $result->configHashes);
        self::assertSame([], $result->codeHashes);
        self::assertNotEmpty($result->totalHash);
    }

    #[Test]
    public function singleExtensionCompilesSuccessfully(): void
    {
        $path = $this->createExtensionDir('single');

        $manifests = [
            ExtensionManifest::fromArray([
                'name' => 'vendor/single',
                'version' => '2.0.0',
                'extension_class' => 'Vendor\\Single\\Extension',
            ], $path),
        ];

        $compiler = new ExtensionGraphCompiler();
        $result = $compiler->compile($manifests);

        self::assertCount(1, $result->extensions);
        self::assertSame('vendor/single', $result->extensions[0]->name);
        self::assertSame('2.0.0', $result->extensions[0]->version);
        self::assertTrue($result->extensions[0]->enabled);
    }

    #[Test]
    public function dependencyOrderIsRespected(): void
    {
        $pathCore = $this->createExtensionDir('core');
        $pathAuth = $this->createExtensionDir('auth');
        $pathAdmin = $this->createExtensionDir('admin');

        $manifests = [
            ExtensionManifest::fromArray([
                'name' => 'vendor/admin',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\Admin\\Extension',
                'requires' => ['vendor/auth' => '>=1.0.0'],
            ], $pathAdmin),
            ExtensionManifest::fromArray([
                'name' => 'vendor/auth',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\Auth\\Extension',
                'requires' => ['vendor/core' => '>=1.0.0'],
            ], $pathAuth),
            ExtensionManifest::fromArray([
                'name' => 'vendor/core',
                'version' => '1.0.0',
                'extension_class' => 'Vendor\\Core\\Extension',
            ], $pathCore),
        ];

        $compiler = new ExtensionGraphCompiler();
        $result = $compiler->compile($manifests);

        $names = array_map(
            static fn($entry) => $entry->name,
            $result->extensions,
        );

        // Core must come before Auth, Auth before Admin
        $coreIdx = array_search('vendor/core', $names, true);
        $authIdx = array_search('vendor/auth', $names, true);
        $adminIdx = array_search('vendor/admin', $names, true);

        self::assertLessThan($authIdx, $coreIdx);
        self::assertLessThan($adminIdx, $authIdx);
    }

    private function createExtensionDir(string $name): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $name;
        mkdir($path, 0o750, true);

        return $path;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
