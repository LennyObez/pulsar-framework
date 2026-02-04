<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Context;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\Exception\ContextException;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;

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

    private function createContext(): RequestContext
    {
        return new RequestContext(
            correlationId: CorrelationId::fromString(str_repeat('aa', 16)),
            causationId: CausationId::fromString(str_repeat('bb', 16)),
        );
    }
}
