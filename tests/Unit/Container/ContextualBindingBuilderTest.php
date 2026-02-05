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
