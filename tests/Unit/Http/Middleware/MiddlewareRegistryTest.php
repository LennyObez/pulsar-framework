<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

#[CoversClass(MiddlewareRegistry::class)]
final class MiddlewareRegistryTest extends TestCase
{
    #[Test]
    public function aliasResolvesToSingleMiddleware(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->alias('auth', StubAuthMiddleware::class);

        $resolved = $registry->resolve('auth');
        self::assertSame([StubAuthMiddleware::class], $resolved);
    }

    #[Test]
    public function groupResolvesToMultipleMiddleware(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->group('web', [StubAuthMiddleware::class, StubCorsMiddleware::class]);

        $resolved = $registry->resolve('web');
        self::assertSame([StubAuthMiddleware::class, StubCorsMiddleware::class], $resolved);
    }

    #[Test]
    public function unknownNameFallsToClassString(): void
    {
        $registry = new MiddlewareRegistry();

        $resolved = $registry->resolve(StubAuthMiddleware::class);
        self::assertSame([StubAuthMiddleware::class], $resolved);
    }

    #[Test]
    public function aliasTakesPriorityOverGroup(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->alias('auth', StubAuthMiddleware::class);
        $registry->group('auth', [StubCorsMiddleware::class]);

        // Alias wins
        $resolved = $registry->resolve('auth');
        self::assertSame([StubAuthMiddleware::class], $resolved);
    }

    #[Test]
    public function hasGroupReturnsCorrectValues(): void
    {
        $registry = new MiddlewareRegistry();

        self::assertFalse($registry->hasGroup('web'));

        $registry->group('web', [StubAuthMiddleware::class]);

        self::assertTrue($registry->hasGroup('web'));
        self::assertFalse($registry->hasGroup('api'));
    }

    #[Test]
    public function hasAliasReturnsCorrectValues(): void
    {
        $registry = new MiddlewareRegistry();

        self::assertFalse($registry->hasAlias('auth'));

        $registry->alias('auth', StubAuthMiddleware::class);

        self::assertTrue($registry->hasAlias('auth'));
        self::assertFalse($registry->hasAlias('cors'));
    }

    #[Test]
    public function aliasWithInstanceMiddleware(): void
    {
        $registry = new MiddlewareRegistry();
        $instance = new StubAuthMiddleware();
        $registry->alias('auth', $instance);

        $resolved = $registry->resolve('auth');
        self::assertCount(1, $resolved);
        self::assertSame($instance, $resolved[0]);
    }
}

/**
 * @internal
 */
class StubAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        return $next($request);
    }
}

/**
 * @internal
 */
class StubCorsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        return $next($request);
    }
}
