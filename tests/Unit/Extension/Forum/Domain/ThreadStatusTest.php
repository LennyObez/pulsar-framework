<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ThreadStatus;

#[CoversClass(ThreadStatus::class)]
final class ThreadStatusTest extends TestCase
{
    #[Test]
    public function allStatusesHaveExpectedStringValues(): void
    {
        self::assertSame('open', ThreadStatus::Open->value);
        self::assertSame('closed', ThreadStatus::Closed->value);
        self::assertSame('locked', ThreadStatus::Locked->value);
    }

    #[Test]
    #[DataProvider('validTransitionsProvider')]
    public function canTransitionToValidPair(ThreadStatus $from, ThreadStatus $to): void
    {
        self::assertTrue($from->canTransitionTo($to));
    }

    /**
     * @return iterable<string, array{ThreadStatus, ThreadStatus}>
     */
    public static function validTransitionsProvider(): iterable
    {
        yield 'Open -> Closed' => [ThreadStatus::Open, ThreadStatus::Closed];
        yield 'Open -> Locked' => [ThreadStatus::Open, ThreadStatus::Locked];
        yield 'Closed -> Open' => [ThreadStatus::Closed, ThreadStatus::Open];
        yield 'Closed -> Locked' => [ThreadStatus::Closed, ThreadStatus::Locked];
        yield 'Locked -> Open' => [ThreadStatus::Locked, ThreadStatus::Open];
        yield 'Locked -> Closed' => [ThreadStatus::Locked, ThreadStatus::Closed];
    }

    #[Test]
    #[DataProvider('allStatusesProvider')]
    public function cannotTransitionToSelf(ThreadStatus $status): void
    {
        self::assertFalse($status->canTransitionTo($status));
    }

    /**
     * @return iterable<string, array{ThreadStatus}>
     */
    public static function allStatusesProvider(): iterable
    {
        foreach (ThreadStatus::cases() as $status) {
            yield $status->value => [$status];
        }
    }

    #[Test]
    public function allowsRepliesOnlyWhenOpen(): void
    {
        self::assertTrue(ThreadStatus::Open->allowsReplies());
        self::assertFalse(ThreadStatus::Closed->allowsReplies());
        self::assertFalse(ThreadStatus::Locked->allowsReplies());
    }

    #[Test]
    public function labelReturnsHumanReadableString(): void
    {
        self::assertSame('Open', ThreadStatus::Open->label());
        self::assertSame('Closed', ThreadStatus::Closed->label());
        self::assertSame('Locked', ThreadStatus::Locked->label());
    }

    #[Test]
    public function fromValidValueReturnsCorrectCase(): void
    {
        self::assertSame(ThreadStatus::Open, ThreadStatus::from('open'));
        self::assertSame(ThreadStatus::Closed, ThreadStatus::from('closed'));
        self::assertSame(ThreadStatus::Locked, ThreadStatus::from('locked'));
    }

    #[Test]
    public function tryFromInvalidValueReturnsNull(): void
    {
        self::assertNull(ThreadStatus::tryFrom('nonexistent'));
    }
}
