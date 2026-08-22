<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event\Attribute;

use Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\Attribute\ListensTo;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use stdClass;

final class ListensToTest extends TestCase
{
    #[Test]
    public function constructorSetsEventAndPriority(): void
    {
        $attr = new ListensTo(event: stdClass::class, priority: 10);

        self::assertSame(stdClass::class, $attr->event);
        self::assertSame(10, $attr->priority);
    }

    #[Test]
    public function defaultPriorityIsZero(): void
    {
        $attr = new ListensTo(event: stdClass::class);

        self::assertSame(0, $attr->priority);
    }

    #[Test]
    public function attributeIsRepeatable(): void
    {
        $ref = new ReflectionClass(ListensTo::class);
        $attrs = $ref->getAttributes(Attribute::class);

        self::assertNotEmpty($attrs);
        $instance = $attrs[0]->newInstance();
        self::assertTrue(($instance->flags & Attribute::IS_REPEATABLE) !== 0);
    }

    #[Test]
    public function attributeCanBeAppliedMultipleTimes(): void
    {
        $class = new class {
            #[ListensTo(stdClass::class)]
            #[ListensTo(RuntimeException::class, priority: 5)]
            public function handleMultiple(): void {}
        };

        $ref = new ReflectionMethod($class, 'handleMultiple');
        $attrs = $ref->getAttributes(ListensTo::class);

        self::assertCount(2, $attrs);

        $first = $attrs[0]->newInstance();
        $second = $attrs[1]->newInstance();

        self::assertSame(stdClass::class, $first->event);
        self::assertSame(RuntimeException::class, $second->event);
        self::assertSame(5, $second->priority);
    }
}
