<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Repl\ReadOnlyContainer;
use Pulsar\Console\Repl\ReplSafeModeException;
use Pulsar\Container\ContainerInterface;
use stdClass;

#[CoversClass(ReadOnlyContainer::class)]
final class ReadOnlyContainerTest extends TestCase
{
    /** @var ContainerInterface&Stub */
    private ContainerInterface $inner;
    private ReadOnlyContainer $container;

    protected function setUp(): void
    {
        $this->inner = $this->createStub(ContainerInterface::class);
        $this->container = new ReadOnlyContainer($this->inner);
    }

    #[Test]
    public function getDelegates(): void
    {
        $expected = new stdClass();
        $this->inner->method('get')->willReturn($expected);

        self::assertSame($expected, $this->container->get('some.service'));
    }

    #[Test]
    public function hasDelegates(): void
    {
        $this->inner->method('has')->willReturn(true);

        self::assertTrue($this->container->has('some.service'));
    }

    #[Test]
    public function getBindingsDelegates(): void
    {
        $this->inner->method('getBindings')->willReturn(['a', 'b']);

        self::assertSame(['a', 'b'], $this->container->getBindings());
    }

    #[Test]
    public function getInstancesDelegates(): void
    {
        $this->inner->method('getInstances')->willReturn(['x']);

        self::assertSame(['x'], $this->container->getInstances());
    }

    #[Test]
    public function bindThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);
        $this->expectExceptionMessageMatches('/container mutation/');

        $this->container->bind('id', stdClass::class);
    }

    #[Test]
    public function instanceThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);
        $this->expectExceptionMessageMatches('/container mutation/');

        $this->container->instance('id', new stdClass());
    }

    #[Test]
    public function forgetInstanceThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);
        $this->expectExceptionMessageMatches('/container mutation/');

        $this->container->forgetInstance('id');
    }

    #[Test]
    public function setResolutionHintsThrows(): void
    {
        $this->expectException(ReplSafeModeException::class);
        $this->expectExceptionMessageMatches('/container mutation/');

        $this->container->setResolutionHints(null);
    }
}
