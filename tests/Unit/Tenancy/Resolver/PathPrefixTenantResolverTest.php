<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy\Resolver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\TenancyConfig;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Tenancy\Resolver\PathPrefixTenantResolver;
use Pulsar\Tenancy\TenantResolverStrategy;

#[CoversClass(PathPrefixTenantResolver::class)]
final class PathPrefixTenantResolverTest extends TestCase
{
    #[Test]
    public function resolvesTenantFromPath(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Path,
            pathPrefix: '/t/',
            tenants: ['acme' => ['name' => 'Acme Corp']],
        );

        $resolver = new PathPrefixTenantResolver($config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/t/acme/dashboard',
        );

        $tenant = $resolver->resolve($request);

        self::assertNotNull($tenant);
        self::assertSame('acme', $tenant->id);
        self::assertSame('Acme Corp', $tenant->name);
    }

    #[Test]
    public function returnsNullWhenPathDoesNotMatchPrefix(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Path,
            pathPrefix: '/t/',
            tenants: ['acme' => ['name' => 'Acme Corp']],
        );

        $resolver = new PathPrefixTenantResolver($config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/acme/dashboard',
        );

        self::assertNull($resolver->resolve($request));
    }

    #[Test]
    public function returnsNullWhenTenantNotInConfig(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Path,
            pathPrefix: '/t/',
            tenants: ['acme' => ['name' => 'Acme Corp']],
        );

        $resolver = new PathPrefixTenantResolver($config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/t/unknown/dashboard',
        );

        self::assertNull($resolver->resolve($request));
    }
}
