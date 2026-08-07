<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Middleware\CompressionMiddleware;
use Pulsar\Http\Middleware\CorsMiddleware;
use Pulsar\Http\Middleware\MiddlewareAliasConfig;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\Middleware\RateLimitMiddleware;
use Pulsar\Http\Middleware\RequestNormalizationMiddleware;
use Pulsar\Security\Csrf\CsrfMiddleware;

use function class_exists;
use function implode;
use function in_array;
use function interface_exists;

final class MiddlewareAliasConfigTest extends TestCase
{
    #[Test]
    public function defaultAliasesIncludeExpectedKeys(): void
    {
        $aliases = MiddlewareAliasConfig::defaultAliases();

        self::assertArrayHasKey('cors', $aliases);
        self::assertArrayHasKey('csrf', $aliases);
        self::assertArrayHasKey('rate-limit', $aliases);
        self::assertArrayHasKey('no-cache', $aliases);
        self::assertArrayHasKey('compression', $aliases);
        self::assertArrayHasKey('tracing', $aliases);
        self::assertArrayHasKey('metrics', $aliases);
    }

    #[Test]
    public function defaultGroupsIncludeWebAndApi(): void
    {
        $groups = MiddlewareAliasConfig::defaultGroups();

        self::assertArrayHasKey('web', $groups);
        self::assertArrayHasKey('api', $groups);
    }

    /**
     * Asserting per-group membership one middleware at a time is what let a group
     * ship missing an entry: every assertion passed, and the absent one was the one
     * nobody named. Pin the whole ordered composition instead, so a removal fails.
     *
     * Anti-automation is not in either list. That claim belongs to
     * MiddlewareGroupBootTest, which reads the registry a real boot produces.
     */
    #[Test]
    public function defaultGroupsCarryTheirFullComposition(): void
    {
        $groups = MiddlewareAliasConfig::defaultGroups();

        self::assertSame(
            [
                RequestNormalizationMiddleware::class,
                CsrfMiddleware::class,
                CompressionMiddleware::class,
            ],
            $groups['web'],
        );

        self::assertSame(
            [
                RequestNormalizationMiddleware::class,
                CorsMiddleware::class,
            ],
            $groups['api'],
        );
    }

    /**
     * The limiter is bound only when `rate_limiting.enabled` is true. A static group
     * naming it costs nothing at boot and returns 500 on the first request to that
     * group, for an operator whose only action was turning rate limiting off.
     */
    #[Test]
    public function noDefaultGroupNamesTheConditionalRateLimiter(): void
    {
        $named = [];

        foreach (MiddlewareAliasConfig::defaultGroups() as $name => $middleware) {
            if (in_array(RateLimitMiddleware::class, $middleware, true)) {
                $named[] = $name;
            }
        }

        self::assertSame([], $named, 'static group(s) naming the conditional limiter: ' . implode(', ', $named));
    }

    #[Test]
    public function applyDefaultsRegistersAllAliasesAndGroups(): void
    {
        $registry = new MiddlewareRegistry();

        MiddlewareAliasConfig::applyDefaults($registry);

        // Check aliases are registered
        self::assertTrue($registry->hasAlias('cors'));
        self::assertTrue($registry->hasAlias('csrf'));
        self::assertTrue($registry->hasAlias('rate-limit'));

        // Check groups are registered
        self::assertTrue($registry->hasGroup('web'));
        self::assertTrue($registry->hasGroup('api'));
    }

    #[Test]
    public function applyDefaultsGroupsResolveToMiddlewareClasses(): void
    {
        $registry = new MiddlewareRegistry();

        MiddlewareAliasConfig::applyDefaults($registry);

        // API group should resolve through aliases to concrete classes
        $resolved = $registry->resolve('api');

        self::assertNotEmpty($resolved);
    }

    #[Test]
    public function defaultAliasesMapToExistingClasses(): void
    {
        foreach (MiddlewareAliasConfig::defaultAliases() as $alias => $class) {
            self::assertTrue(
                class_exists($class) || interface_exists($class),
                "Alias '{$alias}' maps to non-existent class '{$class}'",
            );
        }
    }
}
