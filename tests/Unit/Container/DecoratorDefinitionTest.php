<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\DecoratorDefinition;
use stdClass;

#[CoversClass(DecoratorDefinition::class)]
final class DecoratorDefinitionTest extends TestCase
{
    #[Test]
    public function constructionWithClassString(): void
    {
        $class = stdClass::class;

        $definition = new DecoratorDefinition(
            decorator: $class,
            priority: 10,
        );

        self::assertSame($class, $definition->decorator);
        self::assertSame(10, $definition->priority);
    }

    #[Test]
    public function constructionWithClosure(): void
    {
        $factory = static fn(object $inner): object => $inner;
        $definition = new DecoratorDefinition(decorator: $factory);

        self::assertSame($factory, $definition->decorator);
    }

    #[Test]
    public function defaultPriorityIsZero(): void
    {
        $definition = new DecoratorDefinition(decorator: stdClass::class);

        self::assertSame(0, $definition->priority);
    }

    #[Test]
    public function negativePriorityIsAllowed(): void
    {
        $definition = new DecoratorDefinition(decorator: stdClass::class, priority: -100);

        self::assertSame(-100, $definition->priority);
    }

    #[Test]
    public function decoratorCanBeClosureInstance(): void
    {
        $factory = static fn(): object => new stdClass();
        $definition = new DecoratorDefinition(decorator: $factory);

        self::assertInstanceOf(Closure::class, $definition->decorator);
    }
}
