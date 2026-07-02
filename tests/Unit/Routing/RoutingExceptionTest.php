<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\RoutingException;

#[CoversClass(RoutingException::class)]
final class RoutingExceptionTest extends TestCase
{
    #[Test]
    public function notFoundCreatesExceptionWithPath(): void
    {
        $exception = RoutingException::notFound('/users/42');

        self::assertStringContainsString('/users/42', $exception->getMessage());
        self::assertSame(404, $exception->getCode());
    }

    #[Test]
    public function notImplementedCreatesException501(): void
    {
        // FR-22: an unrecognized HTTP method yields a 501 routing exception.
        $exception = RoutingException::notImplemented('PROPFIND');

        self::assertStringContainsString('PROPFIND', $exception->getMessage());
        self::assertSame(501, $exception->getCode());
        self::assertTrue($exception->isNotImplemented());
        self::assertFalse($exception->isNotFound());
        self::assertFalse($exception->isMethodNotAllowed());
    }

    #[Test]
    public function isNotFoundReturnsTrueFor404(): void
    {
        $exception = RoutingException::notFound('/test');

        self::assertTrue($exception->isNotFound());
        self::assertFalse($exception->isMethodNotAllowed());
    }

    #[Test]
    public function methodNotAllowedCreatesExceptionWithDetails(): void
    {
        $exception = RoutingException::methodNotAllowed(
            '/users',
            Method::POST,
            [Method::GET, Method::HEAD],
        );

        self::assertStringContainsString('POST', $exception->getMessage());
        self::assertStringContainsString('/users', $exception->getMessage());
        self::assertSame(405, $exception->getCode());
    }

    #[Test]
    public function isMethodNotAllowedReturnsTrueFor405(): void
    {
        $exception = RoutingException::methodNotAllowed(
            '/test',
            Method::DELETE,
            [Method::GET],
        );

        self::assertTrue($exception->isMethodNotAllowed());
        self::assertFalse($exception->isNotFound());
    }

    #[Test]
    public function getAllowHeaderReturnsFormattedMethods(): void
    {
        $exception = RoutingException::methodNotAllowed(
            '/test',
            Method::POST,
            [Method::GET, Method::HEAD, Method::OPTIONS],
        );

        self::assertSame('GET, HEAD, OPTIONS', $exception->getAllowHeader());
    }

    #[Test]
    public function getAllowHeaderReturnsEmptyForNotFound(): void
    {
        $exception = RoutingException::notFound('/test');

        self::assertSame('', $exception->getAllowHeader());
    }

    #[Test]
    public function allowedMethodsPropertyIsSetByMethodNotAllowed(): void
    {
        $exception = RoutingException::methodNotAllowed(
            '/test',
            Method::POST,
            [Method::GET, Method::PUT],
        );

        self::assertSame([Method::GET, Method::PUT], $exception->allowedMethods);
    }

    #[Test]
    public function routerLockedCreatesExceptionWith423(): void
    {
        $exception = RoutingException::routerLocked();

        self::assertSame(423, $exception->getCode());
        self::assertStringContainsString('locked', $exception->getMessage());
        self::assertStringContainsString('strict', $exception->getMessage());
    }
}
