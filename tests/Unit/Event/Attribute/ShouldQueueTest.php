<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event\Attribute;

use Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\Attribute\ShouldQueue;
use ReflectionClass;
use ReflectionMethod;

final class ShouldQueueTest extends TestCase
{
    #[Test]
    public function defaultValuesAreSet(): void
    {
        $attr = new ShouldQueue();

        self::assertSame('default', $attr->queue);
        self::assertSame('default', $attr->connection);
        self::assertSame(0, $attr->delay);
        self::assertSame(3, $attr->maxRetries);
    }

    #[Test]
    public function customValuesArePreserved(): void
    {
        $attr = new ShouldQueue(
            queue: 'notifications',
            connection: 'redis',
            delay: 30,
            maxRetries: 5,
        );

        self::assertSame('notifications', $attr->queue);
        self::assertSame('redis', $attr->connection);
        self::assertSame(30, $attr->delay);
        self::assertSame(5, $attr->maxRetries);
    }

    #[Test]
    public function attributeTargetsMethodAndClass(): void
    {
        $ref = new ReflectionClass(ShouldQueue::class);
        $attrs = $ref->getAttributes(Attribute::class);

        self::assertNotEmpty($attrs);
        $instance = $attrs[0]->newInstance();
        $expectedFlags = Attribute::TARGET_METHOD | Attribute::TARGET_CLASS;
        self::assertSame($expectedFlags, $instance->flags);
    }

    #[Test]
    public function attributeCanBeAppliedToMethod(): void
    {
        $class = new class {
            #[ShouldQueue(queue: 'emails')]
            public function handleEvent(): void {}
        };

        $ref = new ReflectionMethod($class, 'handleEvent');
        $attrs = $ref->getAttributes(ShouldQueue::class);

        self::assertCount(1, $attrs);
        $instance = $attrs[0]->newInstance();
        self::assertSame('emails', $instance->queue);
    }
}
