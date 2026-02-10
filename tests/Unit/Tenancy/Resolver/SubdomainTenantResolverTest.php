<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy\Resolver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\TenancyConfig;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Tenancy\Resolver\SubdomainTenantResolver;
use Pulsar\Tenancy\TenantResolverStrategy;

#[CoversClass(SubdomainTenantResolver::class)]
final class SubdomainTenantResolverTest extends TestCase
{
    #[Test]
    public function resolvesTenantFromSubdomain(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Subdomain,
            subdomainSuffix: '.example.com',
            tenants: ['acme' => ['name' => 'Acme Corp']],
        );

        $resolver = new SubdomainTenantResolver($config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/dashboard',
            headers: ['Host' => 'acme.example.com'],
        );

        $tenant = $resolver->resolve($request);

        self::assertNotNull($tenant);
        self::assertSame('acme', $tenant->id);
        self::assertSame('Acme Corp', $tenant->name);
    }

    #[Test]
    public function returnsNullWhenHostDoesNotMatchSuffix(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Subdomain,
            subdomainSuffix: '.example.com',
            tenants: ['acme' => ['name' => 'Acme Corp']],
        );

        $resolver = new SubdomainTenantResolver($config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/dashboard',
            headers: ['Host' => 'acme.other.com'],
        );

        self::assertNull($resolver->resolve($request));
    }

    #[Test]
    public function returnsNullWhenNoHostHeader(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Subdomain,
            subdomainSuffix: '.example.com',
            tenants: ['acme' => ['name' => 'Acme Corp']],
        );

        $resolver = new SubdomainTenantResolver($config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/dashboard',
        );

        self::assertNull($resolver->resolve($request));
    }

    #[Test]
    public function handlesPortInHostHeader(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Subdomain,
            subdomainSuffix: '.example.com',
            tenants: ['acme' => ['name' => 'Acme Corp']],
        );

        $resolver = new SubdomainTenantResolver($config);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/dashboard',
            headers: ['Host' => 'acme.example.com:8080'],
        );

        $tenant = $resolver->resolve($request);

        self::assertNotNull($tenant);
        self::assertSame('acme', $tenant->id);
        self::assertSame('Acme Corp', $tenant->name);
    }
}
