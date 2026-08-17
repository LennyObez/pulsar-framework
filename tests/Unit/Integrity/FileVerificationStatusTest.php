<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Integrity\FileVerificationStatus;

#[CoversNothing]
final class FileVerificationStatusTest extends TestCase
{
    #[Test]
    public function verifiedCaseHasCorrectValue(): void
    {
        self::assertSame('verified', FileVerificationStatus::Verified->value);
    }

    #[Test]
    public function modifiedCaseHasCorrectValue(): void
    {
        self::assertSame('modified', FileVerificationStatus::Modified->value);
    }

    #[Test]
    public function missingCaseHasCorrectValue(): void
    {
        self::assertSame('missing', FileVerificationStatus::Missing->value);
    }

    #[Test]
    public function addedCaseHasCorrectValue(): void
    {
        self::assertSame('added', FileVerificationStatus::Added->value);
    }

    #[Test]
    public function hasFourCases(): void
    {
        self::assertCount(4, FileVerificationStatus::cases());
    }

    #[Test]
    public function canBeCreatedFromString(): void
    {
        self::assertSame(FileVerificationStatus::Verified, FileVerificationStatus::from('verified'));
        self::assertSame(FileVerificationStatus::Modified, FileVerificationStatus::from('modified'));
        self::assertSame(FileVerificationStatus::Missing, FileVerificationStatus::from('missing'));
        self::assertSame(FileVerificationStatus::Added, FileVerificationStatus::from('added'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(FileVerificationStatus::tryFrom('unknown'));
        self::assertNull(FileVerificationStatus::tryFrom(''));
    }
}
