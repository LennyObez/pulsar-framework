<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Workflow;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Workflow\ContentLock;
use ReflectionClass;

#[CoversClass(ContentLock::class)]
final class ContentLockTest extends TestCase
{
    private const string CONTENT_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const string USER_ID = '01912345-6789-7abc-8def-0123456789cd';

    #[Test]
    public function constructionWithAllFields(): void
    {
        $lockedAt = new DateTimeImmutable();
        $expiresAt = $lockedAt->modify('+30 minutes');

        $lock = new ContentLock(
            contentId: self::CONTENT_ID,
            lockedBy: self::USER_ID,
            lockedAt: $lockedAt,
            expiresAt: $expiresAt,
            locale: 'en',
        );

        self::assertSame(self::CONTENT_ID, $lock->contentId);
        self::assertSame(self::USER_ID, $lock->lockedBy);
        self::assertSame($lockedAt, $lock->lockedAt);
        self::assertSame($expiresAt, $lock->expiresAt);
        self::assertSame('en', $lock->locale);
    }

    #[Test]
    public function constructionWithNullLocale(): void
    {
        $lock = new ContentLock(
            contentId: self::CONTENT_ID,
            lockedBy: self::USER_ID,
            lockedAt: new DateTimeImmutable(),
            expiresAt: new DateTimeImmutable('+30 minutes'),
            locale: null,
        );

        self::assertNull($lock->locale);
    }

    #[Test]
    public function isExpiredReturnsTrueForPastExpiry(): void
    {
        $lock = new ContentLock(
            contentId: self::CONTENT_ID,
            lockedBy: self::USER_ID,
            lockedAt: new DateTimeImmutable('-1 hour'),
            expiresAt: new DateTimeImmutable('-1 minute'),
            locale: null,
        );

        self::assertTrue($lock->isExpired());
    }

    #[Test]
    public function isExpiredReturnsFalseForFutureExpiry(): void
    {
        $lock = new ContentLock(
            contentId: self::CONTENT_ID,
            lockedBy: self::USER_ID,
            lockedAt: new DateTimeImmutable(),
            expiresAt: new DateTimeImmutable('+30 minutes'),
            locale: null,
        );

        self::assertFalse($lock->isExpired());
    }

    #[Test]
    public function isReadonlyClass(): void
    {
        $reflection = new ReflectionClass(ContentLock::class);
        self::assertTrue($reflection->isReadOnly());
    }
}
