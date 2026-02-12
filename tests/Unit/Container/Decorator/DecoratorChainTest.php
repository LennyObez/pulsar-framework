<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Decorator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Container\Decorator\DecoratorChain;
use Pulsar\Container\DecoratorDefinition;

#[CoversClass(DecoratorChain::class)]
#[CoversClass(DecoratorDefinition::class)]
final class DecoratorChainTest extends TestCase
{
    #[Test]
    public function appliesDecoratorsInPriorityOrder(): void
    {
        $container = new Container();
        $inner = new InnerService();

        $decorators = [
            new DecoratorDefinition(OuterDecorator::class, 10),
            new DecoratorDefinition(MiddleDecorator::class, 5),
        ];

        /** @var DecorableInterface $result */
        $result = DecoratorChain::resolve($inner, $decorators, $container);

        // OuterDecorator (priority 10) should wrap MiddleDecorator (priority 5) which wraps InnerService
        self::assertSame('outer(middle(inner))', $result->describe());
    }

    #[Test]
    public function closureDecoratorWorks(): void
    {
        $container = new Container();
        $inner = new InnerService();

        $decorators = [
            new DecoratorDefinition(static function (object $inner) {
                return new MiddleDecorator($inner);
            }, 0),
        ];

        /** @var DecorableInterface $result */
        $result = DecoratorChain::resolve($inner, $decorators, $container);

        self::assertSame('middle(inner)', $result->describe());
    }

    #[Test]
    public function emptyDecoratorsReturnsInner(): void
    {
        $container = new Container();
        $inner = new InnerService();

        $result = DecoratorChain::resolve($inner, [], $container);

        self::assertSame($inner, $result);
    }
}

interface DecorableInterface
{
    public function describe(): string;
}

class InnerService implements DecorableInterface
{
    public function describe(): string
    {
        return 'inner';
    }
}

class MiddleDecorator implements DecorableInterface
{
    public function __construct(private readonly DecorableInterface $inner) {}

    public function describe(): string
    {
        return 'middle(' . $this->inner->describe() . ')';
    }
}

class OuterDecorator implements DecorableInterface
{
    public function __construct(private readonly DecorableInterface $inner) {}

    public function describe(): string
    {
        return 'outer(' . $this->inner->describe() . ')';
    }
}
