<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Routing\RoutingException;

/**
 * Coverage for RoutingException factory methods not covered
 * in the primary RoutingExceptionTest.
 */
#[CoversClass(RoutingException::class)]
final class RoutingExceptionCoverageTest extends TestCase
{
    #[Test]
    public function invalidHandlerContainsClassName(): void
    {
        $exception = RoutingException::invalidHandler('App\\Controller\\BrokenController');

        self::assertStringContainsString('App\\Controller\\BrokenController', $exception->getMessage());
        self::assertStringContainsString('callable', $exception->getMessage());
    }

    #[Test]
    public function nonCallableHandlerHasMessage(): void
    {
        $exception = RoutingException::nonCallableHandler();

        self::assertStringContainsString('Invalid route handler', $exception->getMessage());
    }

    #[Test]
    public function unexpectedReturnTypeContainsType(): void
    {
        $exception = RoutingException::unexpectedReturnType('array');

        self::assertStringContainsString('array', $exception->getMessage());
        self::assertStringContainsString('Response', $exception->getMessage());
    }

    #[Test]
    public function unexpectedReturnTypeWithObjectType(): void
    {
        $exception = RoutingException::unexpectedReturnType('stdClass');

        self::assertStringContainsString('stdClass', $exception->getMessage());
    }

    #[Test]
    public function routerLockedExceptionHas423Code(): void
    {
        $exception = RoutingException::routerLocked();

        self::assertSame(423, $exception->getCode());
        self::assertFalse($exception->isNotFound());
        self::assertFalse($exception->isMethodNotAllowed());
    }

    #[Test]
    public function notFoundExceptionHasEmptyAllowedMethods(): void
    {
        $exception = RoutingException::notFound('/missing');

        self::assertSame([], $exception->allowedMethods);
    }

    #[Test]
    public function invalidHandlerDefaultCodeIsZero(): void
    {
        $exception = RoutingException::invalidHandler('Foo\\Bar');

        self::assertSame(0, $exception->getCode());
        self::assertFalse($exception->isNotFound());
        self::assertFalse($exception->isMethodNotAllowed());
    }
}
