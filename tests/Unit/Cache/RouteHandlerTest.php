<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\RouteHandler;
use Pulsar\Cache\RouteHandlerType;

#[CoversClass(RouteHandler::class)]
final class RouteHandlerTest extends TestCase
{
    #[Test]
    public function invokableHandler(): void
    {
        $handler = new RouteHandler(
            type: RouteHandlerType::Invokable,
            resolvable: 'App\\Controller\\HomeController',
        );

        self::assertSame(RouteHandlerType::Invokable, $handler->type);
        self::assertSame('App\\Controller\\HomeController', $handler->resolvable);
        self::assertNull($handler->method);
    }

    #[Test]
    public function methodHandler(): void
    {
        $handler = new RouteHandler(
            type: RouteHandlerType::Method,
            resolvable: 'App\\Controller\\UserController',
            method: 'index',
        );

        self::assertSame(RouteHandlerType::Method, $handler->type);
        self::assertSame('App\\Controller\\UserController', $handler->resolvable);
        self::assertSame('index', $handler->method);
    }

    #[Test]
    public function routeHandlerTypeValues(): void
    {
        self::assertSame('invokable', RouteHandlerType::Invokable->value);
        self::assertSame('method', RouteHandlerType::Method->value);
    }
}
