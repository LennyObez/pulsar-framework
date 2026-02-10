<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Container\ContextualBindingBuilder;
use stdClass;

#[CoversClass(ContextualBindingBuilder::class)]
#[CoversClass(Container::class)]
final class ContextualBindingBuilderTest extends TestCase
{
    #[Test]
    public function contextualBindingResolves(): void
    {
        $container = new Container();

        $container->bind(ContextualConsumer::class, ContextualConsumer::class);
        $container->bind(ContextualAbstract::class, ContextualDefaultImpl::class);

        $container->when(ContextualConsumer::class)
            ->needs(ContextualAbstract::class)
            ->give(ContextualSpecialImpl::class);

        /** @var ContextualConsumer $consumer */
        $consumer = $container->get(ContextualConsumer::class);

        self::assertInstanceOf(ContextualSpecialImpl::class, $consumer->dep);
    }

    #[Test]
    public function giveWithoutNeedsThrows(): void
    {
        $container = new Container();
        $builder = new ContextualBindingBuilder('Consumer', $container);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Call needs() before give()');

        $builder->give(stdClass::class);
    }

    #[Test]
    public function contextualBindingWithCallableFactory(): void
    {
        $container = new Container();

        $container->bind(ContextualConsumer::class, ContextualConsumer::class);
        $container->bind(ContextualAbstract::class, ContextualDefaultImpl::class);

        $special = new ContextualSpecialImpl();
        $container->when(ContextualConsumer::class)
            ->needs(ContextualAbstract::class)
            ->give(static fn(): ContextualSpecialImpl => $special);

        /** @var ContextualConsumer $consumer */
        $consumer = $container->get(ContextualConsumer::class);

        self::assertSame($special, $consumer->dep);
    }

    #[Test]
    public function needsReturnsFluentSelf(): void
    {
        $container = new Container();
        $builder = new ContextualBindingBuilder('Consumer', $container);

        $result = $builder->needs(ContextualAbstract::class);

        self::assertSame($builder, $result);
    }

    #[Test]
    public function contextualBindingDoesNotAffectOtherConsumers(): void
    {
        $container = new Container();

        $container->bind(ContextualConsumer::class, ContextualConsumer::class);
        $container->bind(ContextualOtherConsumer::class, ContextualOtherConsumer::class);
        $container->bind(ContextualAbstract::class, ContextualDefaultImpl::class);

        // Only ContextualConsumer gets the special impl
        $container->when(ContextualConsumer::class)
            ->needs(ContextualAbstract::class)
            ->give(ContextualSpecialImpl::class);

        /** @var ContextualConsumer $consumer */
        $consumer = $container->get(ContextualConsumer::class);
        /** @var ContextualOtherConsumer $other */
        $other = $container->get(ContextualOtherConsumer::class);

        self::assertInstanceOf(ContextualSpecialImpl::class, $consumer->dep);
        self::assertInstanceOf(ContextualDefaultImpl::class, $other->dep);
    }
}

class ContextualAbstract {}

class ContextualDefaultImpl extends ContextualAbstract {}

class ContextualSpecialImpl extends ContextualAbstract {}

class ContextualConsumer
{
    public function __construct(
        public readonly ContextualAbstract $dep,
    ) {}
}

class ContextualOtherConsumer
{
    public function __construct(
        public readonly ContextualAbstract $dep,
    ) {}
}
