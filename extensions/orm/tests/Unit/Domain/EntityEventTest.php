<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\EntityEvent;

final class EntityEventTest extends TestCase
{
    #[Test]
    #[DataProvider('beforeEventsProvider')]
    public function isBeforeReturnsTrueForPreEvents(EntityEvent $event): void
    {
        self::assertTrue($event->isBefore());
    }

    /**
     * @return iterable<string, array{EntityEvent}>
     */
    public static function beforeEventsProvider(): iterable
    {
        yield 'creating' => [EntityEvent::Creating];
        yield 'updating' => [EntityEvent::Updating];
        yield 'deleting' => [EntityEvent::Deleting];
    }

    #[Test]
    #[DataProvider('afterEventsProvider')]
    public function isBeforeReturnsFalseForPostEvents(EntityEvent $event): void
    {
        self::assertFalse($event->isBefore());
    }

    /**
     * @return iterable<string, array{EntityEvent}>
     */
    public static function afterEventsProvider(): iterable
    {
        yield 'created' => [EntityEvent::Created];
        yield 'updated' => [EntityEvent::Updated];
        yield 'deleted' => [EntityEvent::Deleted];
    }

    #[Test]
    public function postEventReturnsCorrectMapping(): void
    {
        self::assertSame(EntityEvent::Created, EntityEvent::Creating->postEvent());
        self::assertSame(EntityEvent::Updated, EntityEvent::Updating->postEvent());
        self::assertSame(EntityEvent::Deleted, EntityEvent::Deleting->postEvent());
    }

    #[Test]
    public function postEventOnPostEventReturnsSelf(): void
    {
        self::assertSame(EntityEvent::Created, EntityEvent::Created->postEvent());
        self::assertSame(EntityEvent::Updated, EntityEvent::Updated->postEvent());
        self::assertSame(EntityEvent::Deleted, EntityEvent::Deleted->postEvent());
    }

    #[Test]
    public function allEventsHaveStringValues(): void
    {
        foreach (EntityEvent::cases() as $case) {
            self::assertNotEmpty($case->value);
        }
    }
}
