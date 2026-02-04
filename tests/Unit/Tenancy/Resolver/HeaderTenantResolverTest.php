<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy\Resolver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\TenancyConfig;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Tenancy\Resolver\HeaderTenantResolver;
use Pulsar\Tenancy\TenantResolverStrategy;

#[CoversClass(HeaderTenantResolver::class)]
final class HeaderTenantResolverTest extends TestCase
{
    #[Test]
    public function resolvesTenantFromHeader(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            headerName: 'X-Tenant-ID',
            tenants: ['acme' => ['name' => 'Acme Corp']],
        );

        $resolver = new HeaderTenantResolver($config);

        $request = new Request(
            method: Method::GET,
            uri: '/test',
            path: '/test',
            queryString: '',
            headers: new HeaderBag(['X-Tenant-ID' => ['acme']]),
            body: '',
        );

        $tenant = $resolver->resolve($request);

        self::assertNotNull($tenant);
        self::assertSame('acme', $tenant->id);
        self::assertSame('Acme Corp', $tenant->name);
    }

    #[Test]
    public function returnsNullWhenHeaderIsMissing(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            headerName: 'X-Tenant-ID',
            tenants: ['acme' => ['name' => 'Acme Corp']],
        );

        $resolver = new HeaderTenantResolver($config);

        $request = new Request(
            method: Method::GET,
            uri: '/test',
            path: '/test',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );

        self::assertNull($resolver->resolve($request));
    }

    #[Test]
    public function returnsNullWhenTenantIdNotInConfig(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            headerName: 'X-Tenant-ID',
            tenants: ['acme' => ['name' => 'Acme Corp']],
        );

        $resolver = new HeaderTenantResolver($config);

        $request = new Request(
            method: Method::GET,
            uri: '/test',
            path: '/test',
            queryString: '',
            headers: new HeaderBag(['X-Tenant-ID' => ['unknown']]),
            body: '',
        );

        self::assertNull($resolver->resolve($request));
    }
}
