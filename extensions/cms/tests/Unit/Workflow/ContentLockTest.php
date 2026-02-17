<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Workflow;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Workflow\ContentLock;

#[CoversClass(ContentLock::class)]
final class ContentLockTest extends TestCase
{
    #[Test]
    public function isExpired_returns_false_for_future_expiry(): void
    {
        $lock = new ContentLock(
            contentId: 'content-1',
            lockedBy: 'user-1',
            lockedAt: new DateTimeImmutable(),
            expiresAt: new DateTimeImmutable('+30 minutes'),
            locale: null,
        );

        self::assertFalse($lock->isExpired());
    }

    #[Test]
    public function isExpired_returns_true_for_past_expiry(): void
    {
        $lock = new ContentLock(
            contentId: 'content-1',
            lockedBy: 'user-1',
            lockedAt: new DateTimeImmutable('-1 hour'),
            expiresAt: new DateTimeImmutable('-30 minutes'),
            locale: null,
        );

        self::assertTrue($lock->isExpired());
    }

    #[Test]
    public function locale_can_be_null_for_all_locales(): void
    {
        $lock = new ContentLock(
            contentId: 'content-1',
            lockedBy: 'user-1',
            lockedAt: new DateTimeImmutable(),
            expiresAt: new DateTimeImmutable('+30 minutes'),
            locale: null,
        );

        self::assertNull($lock->locale);
    }

    #[Test]
    public function locale_can_be_specific(): void
    {
        $lock = new ContentLock(
            contentId: 'content-1',
            lockedBy: 'user-1',
            lockedAt: new DateTimeImmutable(),
            expiresAt: new DateTimeImmutable('+30 minutes'),
            locale: 'en-US',
        );

        self::assertSame('en-US', $lock->locale);
    }
}
