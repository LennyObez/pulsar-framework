<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\QueueDriverType;

#[CoversClass(QueueDriverType::class)]
final class QueueDriverTypeTest extends TestCase
{
    #[Test]
    #[DataProvider('driverProvider')]
    public function backingValuesAreCorrect(QueueDriverType $driver, string $expected): void
    {
        self::assertSame($expected, $driver->value);
    }

    /**
     * @return iterable<string, array{QueueDriverType, string}>
     */
    public static function driverProvider(): iterable
    {
        yield 'sync' => [QueueDriverType::Sync, 'sync'];
        yield 'database' => [QueueDriverType::Database, 'database'];
        yield 'memory' => [QueueDriverType::Memory, 'memory'];
        yield 'redis' => [QueueDriverType::Redis, 'redis'];
        yield 'amqp' => [QueueDriverType::Amqp, 'amqp'];
        yield 'sqs' => [QueueDriverType::Sqs, 'sqs'];
        yield 'pubsub' => [QueueDriverType::PubSub, 'pubsub'];
    }

    #[Test]
    public function allCases(): void
    {
        self::assertCount(7, QueueDriverType::cases());
    }
}
