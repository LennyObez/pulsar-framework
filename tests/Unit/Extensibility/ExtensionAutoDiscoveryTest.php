<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\DiscoveredExtension;
use Pulsar\Extensibility\ExtensionAutoDiscovery;
use Pulsar\Extensibility\ExtensionInterface;

use function json_encode;
use function mkdir;
use function sys_get_temp_dir;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ExtensionAutoDiscovery::class)]
#[CoversClass(DiscoveredExtension::class)]
final class ExtensionAutoDiscoveryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar-autodiscovery-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
    }

    #[Test]
    public function discoverReturnsEmptyWhenNoVendorDir(): void
    {
        $discovery = new ExtensionAutoDiscovery($this->tempDir . '/nonexistent');

        self::assertSame([], $discovery->discover());
    }

    #[Test]
    public function discoverFindsExtensionFromInstalledJson(): void
    {
        $composerDir = $this->tempDir . '/composer';
        mkdir($composerDir, 0o755, true);

        $installedJson = [
            'packages' => [
                [
                    'name' => 'acme/extension',
                    'extra' => [
                        'pulsar' => [
                            'extension' => 'Acme\\Extension\\AcmeExtension',
                        ],
                    ],
                ],
                [
                    'name' => 'acme/other',
                    // No pulsar key — should be skipped
                ],
            ],
        ];

        file_put_contents(
            $composerDir . '/installed.json',
            json_encode($installedJson, JSON_THROW_ON_ERROR),
        );

        $discovery = new ExtensionAutoDiscovery($this->tempDir);
        $result = $discovery->discover();

        self::assertCount(1, $result);
        self::assertSame('acme/extension', $result[0]->packageName);
        self::assertSame('Acme\\Extension\\AcmeExtension', $result[0]->extensionClass);
    }

    #[Test]
    public function discoverSkipsPackagesWithoutPulsarExtra(): void
    {
        $composerDir = $this->tempDir . '/composer';
        mkdir($composerDir, 0o755, true);

        $installedJson = [
            'packages' => [
                ['name' => 'vendor/no-pulsar', 'extra' => ['laravel' => []]],
                ['name' => 'vendor/no-extra'],
            ],
        ];

        file_put_contents(
            $composerDir . '/installed.json',
            json_encode($installedJson, JSON_THROW_ON_ERROR),
        );

        $discovery = new ExtensionAutoDiscovery($this->tempDir);

        self::assertSame([], $discovery->discover());
    }

    #[Test]
    public function discoverFallsBackToDirectoryScan(): void
    {
        // No installed.json — trigger directory scan
        $vendorDir = $this->tempDir . '/acme/my-ext';
        mkdir($vendorDir, 0o755, true);

        $composerJson = [
            'name' => 'acme/my-ext',
            'extra' => [
                'pulsar' => [
                    'extension' => 'Acme\\MyExt\\Extension',
                ],
            ],
        ];

        file_put_contents(
            $vendorDir . '/composer.json',
            json_encode($composerJson, JSON_THROW_ON_ERROR),
        );

        $discovery = new ExtensionAutoDiscovery($this->tempDir);
        $result = $discovery->discover();

        self::assertCount(1, $result);
        self::assertSame('acme/my-ext', $result[0]->packageName);
    }

    #[Test]
    public function discoveredExtensionHoldsCorrectData(): void
    {
        /** @var class-string<ExtensionInterface> $class */
        $class = 'Vendor\\Pkg\\Extension'; // @phpstan-ignore varTag.nativeType
        $ext = new DiscoveredExtension('vendor/pkg', $class);

        self::assertSame('vendor/pkg', $ext->packageName);
        self::assertSame('Vendor\\Pkg\\Extension', $ext->extensionClass);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // Safety: only delete within our known temp directory prefix
        $realDir = realpath($dir);
        $tempBase = realpath(sys_get_temp_dir());

        if ($realDir === false || $tempBase === false || !str_starts_with($realDir, $tempBase)) {
            return;
        }

        /** @var list<string> $items */
        $items = scandir($dir) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $realDir . DIRECTORY_SEPARATOR . $item;
            $realPath = realpath($path);

            if ($realPath === false || !str_starts_with($realPath, $tempBase)) {
                continue;
            }

            if (is_dir($realPath)) {
                $this->recursiveDelete($realPath);
            } else {
                // Safe: path is verified to be within sys_get_temp_dir()
                unlink($realPath); // nosemgrep: path-traversal-unlink
            }
        }

        rmdir($realDir);
    }
}
