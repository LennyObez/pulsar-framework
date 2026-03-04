<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Middleware\CorsMiddleware;
use Pulsar\Http\Middleware\MiddlewareAliasConfig;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\Middleware\RateLimitMiddleware;
use Pulsar\Security\Csrf\CsrfMiddleware;

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

    #[Test]
    public function webGroupIncludesCsrf(): void
    {
        $groups = MiddlewareAliasConfig::defaultGroups();

        self::assertContains(CsrfMiddleware::class, $groups['web']);
    }

    #[Test]
    public function apiGroupIncludesRateLimit(): void
    {
        $groups = MiddlewareAliasConfig::defaultGroups();

        self::assertContains(RateLimitMiddleware::class, $groups['api']);
    }

    #[Test]
    public function apiGroupIncludesCors(): void
    {
        $groups = MiddlewareAliasConfig::defaultGroups();

        self::assertContains(CorsMiddleware::class, $groups['api']);
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
