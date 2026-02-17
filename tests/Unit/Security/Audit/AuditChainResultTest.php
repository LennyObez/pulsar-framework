<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Audit\AuditChainResult;

#[CoversClass(AuditChainResult::class)]
final class AuditChainResultTest extends TestCase
{
    #[Test]
    public function validChainWithAllEntriesPassing(): void
    {
        $result = new AuditChainResult(
            valid: true,
            verifiedCount: 100,
        );

        self::assertTrue($result->valid);
        self::assertSame(100, $result->verifiedCount);
        self::assertSame([], $result->failedEntryIds);
        self::assertSame([], $result->brokenLinks);
    }

    #[Test]
    public function invalidChainWithFailedEntries(): void
    {
        $result = new AuditChainResult(
            valid: false,
            verifiedCount: 98,
            failedEntryIds: ['entry-42', 'entry-99'],
        );

        self::assertFalse($result->valid);
        self::assertSame(98, $result->verifiedCount);
        self::assertSame(['entry-42', 'entry-99'], $result->failedEntryIds);
        self::assertSame([], $result->brokenLinks);
    }

    #[Test]
    public function invalidChainWithBrokenLinks(): void
    {
        $result = new AuditChainResult(
            valid: false,
            verifiedCount: 95,
            failedEntryIds: [],
            brokenLinks: ['entry-50', 'entry-51', 'entry-52'],
        );

        self::assertFalse($result->valid);
        self::assertSame(95, $result->verifiedCount);
        self::assertSame([], $result->failedEntryIds);
        self::assertSame(['entry-50', 'entry-51', 'entry-52'], $result->brokenLinks);
    }

    #[Test]
    public function invalidChainWithBothFailuresAndBrokenLinks(): void
    {
        $result = new AuditChainResult(
            valid: false,
            verifiedCount: 90,
            failedEntryIds: ['entry-10'],
            brokenLinks: ['entry-20'],
        );

        self::assertFalse($result->valid);
        self::assertSame(90, $result->verifiedCount);
        self::assertCount(1, $result->failedEntryIds);
        self::assertCount(1, $result->brokenLinks);
    }

    #[Test]
    public function emptyChainIsValid(): void
    {
        $result = new AuditChainResult(
            valid: true,
            verifiedCount: 0,
        );

        self::assertTrue($result->valid);
        self::assertSame(0, $result->verifiedCount);
    }
}
