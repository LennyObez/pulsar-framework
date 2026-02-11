<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Integrity\FileVerificationResult;
use Pulsar\Integrity\FileVerificationStatus;
use ReflectionClass;

#[CoversClass(FileVerificationResult::class)]
final class FileVerificationResultTest extends TestCase
{
    #[Test]
    public function constructsWithVerifiedStatus(): void
    {
        $result = new FileVerificationResult(
            path: 'src/Kernel.php',
            status: FileVerificationStatus::Verified,
            expectedHash: 'abc123',
            actualHash: 'abc123',
        );

        self::assertSame('src/Kernel.php', $result->path);
        self::assertSame(FileVerificationStatus::Verified, $result->status);
        self::assertSame('abc123', $result->expectedHash);
        self::assertSame('abc123', $result->actualHash);
    }

    #[Test]
    public function constructsWithModifiedStatus(): void
    {
        $result = new FileVerificationResult(
            path: 'config/app.php',
            status: FileVerificationStatus::Modified,
            expectedHash: 'aaa',
            actualHash: 'bbb',
        );

        self::assertSame(FileVerificationStatus::Modified, $result->status);
        self::assertNotSame($result->expectedHash, $result->actualHash);
    }

    #[Test]
    public function constructsWithMissingStatus(): void
    {
        $result = new FileVerificationResult(
            path: 'missing.php',
            status: FileVerificationStatus::Missing,
            expectedHash: 'expected',
        );

        self::assertSame(FileVerificationStatus::Missing, $result->status);
        self::assertSame('expected', $result->expectedHash);
        self::assertNull($result->actualHash);
    }

    #[Test]
    public function constructsWithAddedStatus(): void
    {
        $result = new FileVerificationResult(
            path: 'new-file.php',
            status: FileVerificationStatus::Added,
            actualHash: 'newhash',
        );

        self::assertSame(FileVerificationStatus::Added, $result->status);
        self::assertNull($result->expectedHash);
        self::assertSame('newhash', $result->actualHash);
    }

    #[Test]
    public function hashesDefaultToNull(): void
    {
        $result = new FileVerificationResult(
            path: 'test.php',
            status: FileVerificationStatus::Verified,
        );

        self::assertNull($result->expectedHash);
        self::assertNull($result->actualHash);
    }

    #[Test]
    public function isReadonly(): void
    {
        $ref = new ReflectionClass(FileVerificationResult::class);

        self::assertTrue($ref->isReadOnly());
    }

    #[Test]
    public function isFinal(): void
    {
        $ref = new ReflectionClass(FileVerificationResult::class);

        self::assertTrue($ref->isFinal());
    }
}
