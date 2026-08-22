<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Storage\StorageException;

#[CoversClass(StorageException::class)]
final class StorageExceptionTest extends TestCase
{
    #[Test]
    public function objectNotFound(): void
    {
        $e = StorageException::objectNotFound('avatars/user-42.png');

        self::assertStringContainsString('avatars/user-42.png', $e->getMessage());
    }

    #[Test]
    public function writeFailed(): void
    {
        $e = StorageException::writeFailed('reports/q4.pdf', 'disk full');

        self::assertStringContainsString('reports/q4.pdf', $e->getMessage());
        self::assertStringContainsString('disk full', $e->getMessage());
    }

    #[Test]
    public function deleteFailed(): void
    {
        $e = StorageException::deleteFailed('temp/upload.bin', 'permission denied');

        self::assertStringContainsString('temp/upload.bin', $e->getMessage());
        self::assertStringContainsString('permission denied', $e->getMessage());
    }

    #[Test]
    public function readFailed(): void
    {
        $e = StorageException::readFailed('data/config.json', 'timeout');

        self::assertStringContainsString('data/config.json', $e->getMessage());
        self::assertStringContainsString('timeout', $e->getMessage());
    }

    #[Test]
    public function invalidKey(): void
    {
        $e = StorageException::invalidKey('../etc/passwd', 'path traversal');

        self::assertStringContainsString('../etc/passwd', $e->getMessage());
        self::assertStringContainsString('path traversal', $e->getMessage());
    }

    #[Test]
    public function diskNotFound(): void
    {
        $e = StorageException::diskNotFound('s3-backup');

        self::assertStringContainsString('s3-backup', $e->getMessage());
    }

    #[Test]
    public function connectionFailed(): void
    {
        $e = StorageException::connectionFailed('S3 endpoint unreachable');

        self::assertStringContainsString('S3 endpoint unreachable', $e->getMessage());
    }
}
