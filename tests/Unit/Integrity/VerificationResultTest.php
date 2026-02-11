<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Integrity\FileVerificationResult;
use Pulsar\Integrity\FileVerificationStatus;
use Pulsar\Integrity\VerificationResult;
use ReflectionClass;

#[CoversClass(VerificationResult::class)]
final class VerificationResultTest extends TestCase
{
    #[Test]
    public function passingResultHasCorrectCounts(): void
    {
        $result = new VerificationResult(
            passed: true,
            verified: 10,
            modified: 0,
            missing: 0,
            added: 0,
            files: [],
        );

        self::assertTrue($result->passed);
        self::assertSame(10, $result->verified);
        self::assertSame(0, $result->modified);
        self::assertSame(0, $result->missing);
        self::assertSame(0, $result->added);
    }

    #[Test]
    public function failingResultWithModifications(): void
    {
        $result = new VerificationResult(
            passed: false,
            verified: 8,
            modified: 2,
            missing: 0,
            added: 0,
            files: [],
        );

        self::assertFalse($result->passed);
        self::assertSame(2, $result->modified);
    }

    #[Test]
    public function failingResultWithMissingFiles(): void
    {
        $result = new VerificationResult(
            passed: false,
            verified: 7,
            modified: 0,
            missing: 3,
            added: 0,
            files: [],
        );

        self::assertFalse($result->passed);
        self::assertSame(3, $result->missing);
    }

    #[Test]
    public function resultWithAddedFiles(): void
    {
        $result = new VerificationResult(
            passed: false,
            verified: 5,
            modified: 0,
            missing: 0,
            added: 2,
            files: [],
        );

        self::assertSame(2, $result->added);
    }

    #[Test]
    public function resultContainsFileVerificationResults(): void
    {
        $files = [
            new FileVerificationResult('a.php', FileVerificationStatus::Verified, 'hash1', 'hash1'),
            new FileVerificationResult('b.php', FileVerificationStatus::Modified, 'hash2', 'hash3'),
        ];

        $result = new VerificationResult(
            passed: false,
            verified: 1,
            modified: 1,
            missing: 0,
            added: 0,
            files: $files,
        );

        self::assertCount(2, $result->files);
        self::assertSame('a.php', $result->files[0]->path);
        self::assertSame(FileVerificationStatus::Verified, $result->files[0]->status);
        self::assertSame('b.php', $result->files[1]->path);
        self::assertSame(FileVerificationStatus::Modified, $result->files[1]->status);
    }

    #[Test]
    public function emptyResultWithNoFiles(): void
    {
        $result = new VerificationResult(
            passed: true,
            verified: 0,
            modified: 0,
            missing: 0,
            added: 0,
            files: [],
        );

        self::assertTrue($result->passed);
        self::assertSame([], $result->files);
    }

    #[Test]
    public function isReadonly(): void
    {
        $ref = new ReflectionClass(VerificationResult::class);

        self::assertTrue($ref->isReadOnly());
    }

    #[Test]
    public function isFinal(): void
    {
        $ref = new ReflectionClass(VerificationResult::class);

        self::assertTrue($ref->isFinal());
    }
}
