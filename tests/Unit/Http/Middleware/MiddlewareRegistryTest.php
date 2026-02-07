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
use RuntimeException;

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

    #[Test]
    public function throwsOnCircularAliasReference(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->alias('a', StubAuthMiddleware::class);
        // Create alias 'a' -> alias 'b' -> alias 'a' cycle
        $registry->alias('a', 'b'); // @phpstan-ignore argument.type (intentional: testing cycle detection with non-class-string)
        $registry->alias('b', 'a'); // @phpstan-ignore argument.type (intentional: testing cycle detection with non-class-string)

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular middleware reference detected: "a"');
        $registry->resolve('a');
    }

    #[Test]
    public function throwsOnCircularGroupReference(): void
    {
        $registry = new MiddlewareRegistry();
        // Group 'web' contains alias 'x', which points to group 'web'
        $registry->group('web', ['x']); // @phpstan-ignore argument.type (intentional: testing cycle detection)
        $registry->alias('x', 'web'); // @phpstan-ignore argument.type (intentional: testing cycle detection)
        $registry->group('web', ['x']); // @phpstan-ignore argument.type (intentional: testing cycle detection)

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular middleware reference detected');
        $registry->resolve('web');
    }

    #[Test]
    public function recursivelyResolvesNestedGroups(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->alias('auth', StubAuthMiddleware::class);
        $registry->alias('cors', StubCorsMiddleware::class);
        $registry->group('web', ['auth', 'cors']); // @phpstan-ignore argument.type (intentional: aliases resolve recursively)

        $resolved = $registry->resolve('web');
        self::assertSame([StubAuthMiddleware::class, StubCorsMiddleware::class], $resolved);
    }

    #[Test]
    public function sameAliasInMultipleGroupsDoesNotFalsePositive(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->alias('auth', StubAuthMiddleware::class);
        // 'auth' appears in the group twice — should not trigger cycle detection
        $registry->group('doubled', ['auth', StubCorsMiddleware::class, 'auth']); // @phpstan-ignore argument.type (intentional: aliases resolve recursively)

        $resolved = $registry->resolve('doubled');
        self::assertSame([StubAuthMiddleware::class, StubCorsMiddleware::class, StubAuthMiddleware::class], $resolved);
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
