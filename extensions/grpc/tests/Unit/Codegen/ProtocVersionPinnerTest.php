<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Codegen;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Codegen\ProtocVersionPinner;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(ProtocVersionPinner::class)]
final class ProtocVersionPinnerTest extends TestCase
{
    private ProtocVersionPinner $pinner;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->pinner = new ProtocVersionPinner();
        $this->tempDir = sys_get_temp_dir() . '/version_pinner_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function recordVersionWritesManifest(): void
    {
        $path = $this->tempDir . '/build-metadata.json';

        $this->pinner->recordVersion('25.1', $path);

        self::assertFileExists($path);

        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data);
        self::assertSame('25.1', $data['protoc_version']);
        self::assertArrayHasKey('protoc_pinned_at', $data);
    }

    #[Test]
    public function verifyVersionReturnsTrueWhenMatching(): void
    {
        $path = $this->tempDir . '/build-metadata.json';

        $this->pinner->recordVersion('25.1', $path);

        self::assertTrue($this->pinner->verifyVersion('25.1', $path));
    }

    #[Test]
    public function verifyVersionReturnsFalseWhenMismatch(): void
    {
        $path = $this->tempDir . '/build-metadata.json';

        $this->pinner->recordVersion('25.1', $path);

        self::assertFalse($this->pinner->verifyVersion('24.4', $path));
    }

    #[Test]
    public function verifyVersionReturnsTrueWhenNoManifest(): void
    {
        $path = $this->tempDir . '/nonexistent.json';

        self::assertTrue($this->pinner->verifyVersion('25.1', $path));
    }

    #[Test]
    public function verifyVersionReturnsTrueWhenNoPinnedVersion(): void
    {
        $path = $this->tempDir . '/empty.json';
        file_put_contents($path, '{}');

        self::assertTrue($this->pinner->verifyVersion('25.1', $path));
    }

    #[Test]
    public function getPinnedVersionReturnsVersion(): void
    {
        $path = $this->tempDir . '/build-metadata.json';

        $this->pinner->recordVersion('25.1', $path);

        self::assertSame('25.1', $this->pinner->getPinnedVersion($path));
    }

    #[Test]
    public function getPinnedVersionReturnsNullWhenNotPinned(): void
    {
        self::assertNull($this->pinner->getPinnedVersion($this->tempDir . '/nonexistent.json'));
    }

    #[Test]
    public function recordVersionUpdatesExistingManifest(): void
    {
        $path = $this->tempDir . '/build-metadata.json';

        $this->pinner->recordVersion('24.4', $path);
        $this->pinner->recordVersion('25.1', $path);

        self::assertSame('25.1', $this->pinner->getPinnedVersion($path));
    }

    #[Test]
    public function recordVersionPreservesOtherKeys(): void
    {
        $path = $this->tempDir . '/build-metadata.json';
        file_put_contents($path, json_encode(['custom_key' => 'custom_value']));

        $this->pinner->recordVersion('25.1', $path);

        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data);
        self::assertSame('custom_value', $data['custom_key']);
        self::assertSame('25.1', $data['protoc_version']);
    }

    #[Test]
    public function recordVersionCreatesParentDirectories(): void
    {
        $path = $this->tempDir . '/nested/deep/build-metadata.json';

        $this->pinner->recordVersion('25.1', $path);

        self::assertFileExists($path);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
