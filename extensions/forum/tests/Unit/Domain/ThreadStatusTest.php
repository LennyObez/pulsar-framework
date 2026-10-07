<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ThreadStatus;

final class ThreadStatusTest extends TestCase
{
    #[Test]
    public function openCanTransitionToClosedAndLocked(): void
    {
        self::assertTrue(ThreadStatus::Open->canTransitionTo(ThreadStatus::Closed));
        self::assertTrue(ThreadStatus::Open->canTransitionTo(ThreadStatus::Locked));
    }

    #[Test]
    public function closedCanTransitionToOpenAndLocked(): void
    {
        self::assertTrue(ThreadStatus::Closed->canTransitionTo(ThreadStatus::Open));
        self::assertTrue(ThreadStatus::Closed->canTransitionTo(ThreadStatus::Locked));
    }

    #[Test]
    public function lockedCanTransitionToOpenAndClosed(): void
    {
        self::assertTrue(ThreadStatus::Locked->canTransitionTo(ThreadStatus::Open));
        self::assertTrue(ThreadStatus::Locked->canTransitionTo(ThreadStatus::Closed));
    }

    #[Test]
    public function cannotTransitionToSelf(): void
    {
        self::assertFalse(ThreadStatus::Open->canTransitionTo(ThreadStatus::Open));
        self::assertFalse(ThreadStatus::Closed->canTransitionTo(ThreadStatus::Closed));
        self::assertFalse(ThreadStatus::Locked->canTransitionTo(ThreadStatus::Locked));
    }

    #[Test]
    public function onlyOpenAllowsReplies(): void
    {
        self::assertTrue(ThreadStatus::Open->allowsReplies());
        self::assertFalse(ThreadStatus::Closed->allowsReplies());
        self::assertFalse(ThreadStatus::Locked->allowsReplies());
    }

    #[Test]
    public function labelReturnsHumanReadable(): void
    {
        self::assertSame('Open', ThreadStatus::Open->label());
        self::assertSame('Closed', ThreadStatus::Closed->label());
        self::assertSame('Locked', ThreadStatus::Locked->label());
    }

    #[Test]
    public function backingValues(): void
    {
        self::assertSame('open', ThreadStatus::Open->value);
        self::assertSame('closed', ThreadStatus::Closed->value);
        self::assertSame('locked', ThreadStatus::Locked->value);
    }
}
