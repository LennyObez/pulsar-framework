<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Context;

use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\Exception\ContextException;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;

use function str_repeat;

#[CoversClass(RequestContextHolder::class)]
final class RequestContextHolderTest extends TestCase
{
    #[Test]
    public function setAndGet(): void
    {
        $holder = new RequestContextHolder();
        $context = $this->createContext();

        $holder->set($context);

        self::assertSame($context, $holder->get());
    }

    #[Test]
    public function getThrowsWhenNotAvailable(): void
    {
        $holder = new RequestContextHolder();

        $this->expectException(ContextException::class);
        $this->expectExceptionMessage('not available');

        (void) $holder->get();
    }

    #[Test]
    public function tryGetReturnsNullWhenNotAvailable(): void
    {
        $holder = new RequestContextHolder();

        self::assertNull($holder->tryGet());
    }

    #[Test]
    public function tryGetReturnsContextWhenAvailable(): void
    {
        $holder = new RequestContextHolder();
        $context = $this->createContext();
        $holder->set($context);

        self::assertSame($context, $holder->tryGet());
    }

    #[Test]
    public function isAvailable(): void
    {
        $holder = new RequestContextHolder();

        self::assertFalse($holder->isAvailable());

        $holder->set($this->createContext());

        self::assertTrue($holder->isAvailable());
    }

    #[Test]
    public function clearRemovesContext(): void
    {
        $holder = new RequestContextHolder();
        $holder->set($this->createContext());
        $holder->clear();

        self::assertFalse($holder->isAvailable());
        self::assertNull($holder->tryGet());
    }

    #[Test]
    public function resetRequestStateClearsContext(): void
    {
        $holder = new RequestContextHolder();
        $holder->set($this->createContext());
        $holder->resetRequestState();

        self::assertFalse($holder->isAvailable());
    }

    #[Test]
    public function fibersDoNotShareContext(): void
    {
        $holder = new RequestContextHolder();
        $rootContext = $this->createContext('cc');
        $holder->set($rootContext);

        $fiberContext = $this->createContext('dd');
        $observed = null;

        $fiber = new Fiber(function () use ($holder, $fiberContext, &$observed): void {
            // Fiber starts with no inherited context.
            $observed = ['initial' => $holder->tryGet()];
            $holder->set($fiberContext);
            $observed['fiber_set'] = $holder->tryGet();
        });

        $fiber->start();

        // After the Fiber returns, the root holder must still hold the root context.
        self::assertSame($rootContext, $holder->tryGet());
        self::assertNull($observed['initial']);
        self::assertSame($fiberContext, $observed['fiber_set']);
    }

    #[Test]
    public function clearOnlyAffectsCurrentFiber(): void
    {
        $holder = new RequestContextHolder();
        $rootContext = $this->createContext('cc');
        $holder->set($rootContext);

        $fiber = new Fiber(function () use ($holder): void {
            $holder->set($this->createContext('dd'));
            $holder->clear();
        });

        $fiber->start();

        // Fiber's clear() must NOT have wiped the root context.
        self::assertSame($rootContext, $holder->tryGet());
    }

    #[Test]
    public function twoFibersSeeIndependentContexts(): void
    {
        $holder = new RequestContextHolder();
        $contextA = $this->createContext('aa');
        $contextB = $this->createContext('bb');

        $fiberA = new Fiber(function () use ($holder, $contextA): mixed {
            $holder->set($contextA);
            Fiber::suspend();

            return $holder->tryGet();
        });

        $fiberB = new Fiber(function () use ($holder, $contextB): mixed {
            $holder->set($contextB);
            Fiber::suspend();

            return $holder->tryGet();
        });

        $fiberA->start();
        $fiberB->start();
        $fiberA->resume();
        $fiberB->resume();

        // After both Fibers terminate, fetch the values they observed at
        // their final `tryGet()` call. `Fiber::resume()` returns the next
        // suspended value, so the actual return value is read via getReturn().
        self::assertSame($contextA, $fiberA->getReturn());
        self::assertSame($contextB, $fiberB->getReturn());
    }

    private function createContext(string $byte = 'aa'): RequestContext
    {
        return new RequestContext(
            correlationId: CorrelationId::fromString(str_repeat($byte, 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
        );
    }
}
