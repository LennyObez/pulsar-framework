<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;

use function count;

final class EventTypeTest extends TestCase
{
    #[Test]
    public function httpRequestHasCorrectValue(): void
    {
        self::assertSame('http.request', EventType::HttpRequest->value);
    }

    #[Test]
    public function databaseQueryHasCorrectValue(): void
    {
        self::assertSame('db.query', EventType::DatabaseQuery->value);
    }

    #[Test]
    public function exceptionHasCorrectValue(): void
    {
        self::assertSame('exception', EventType::Exception->value);
    }

    #[Test]
    public function allCasesAreUnique(): void
    {
        $values = array_map(
            static fn(EventType $t): string => $t->value,
            EventType::cases(),
        );

        self::assertSame(count($values), count(array_unique($values)));
    }

    #[Test]
    public function canBeCreatedFromString(): void
    {
        $type = EventType::from('log.entry');

        self::assertSame(EventType::LogEntry, $type);
    }
}
