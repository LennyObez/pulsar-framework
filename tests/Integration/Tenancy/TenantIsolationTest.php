<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Tenancy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\TenancyConfig;
use Pulsar\Config\TenantDatabaseConfig;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Tenancy\Middleware\TenantResolutionMiddleware;
use Pulsar\Tenancy\Resolver\HeaderTenantResolver;
use Pulsar\Tenancy\Resolver\SubdomainTenantResolver;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantAwareConnectionManager;
use Pulsar\Tenancy\TenantContext;
use Pulsar\Tenancy\TenantDatabaseStrategy;
use Pulsar\Tenancy\TenantResolverStrategy;
use RuntimeException;

#[CoversClass(TenantResolutionMiddleware::class)]
#[CoversClass(HeaderTenantResolver::class)]
#[CoversClass(SubdomainTenantResolver::class)]
#[CoversClass(TenantContext::class)]
#[CoversClass(TenantAwareConnectionManager::class)]
#[CoversClass(TenancyConfig::class)]
#[CoversClass(TenantDatabaseConfig::class)]
#[CoversClass(Tenant::class)]
final class TenantIsolationTest extends TestCase
{
    #[Test]
    public function fullPipelineWithHeaderResolverSetsTenantContext(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            headerName: 'X-Tenant-ID',
            tenants: ['acme' => ['name' => 'Acme Corp', 'metadata' => ['plan' => 'enterprise']]],
            defaultTenant: null,
            database: new TenantDatabaseConfig(strategy: TenantDatabaseStrategy::Prefix, prefixTemplate: 'tenant_{tenant_id}_'),
        );

        $resolver = new HeaderTenantResolver($config);
        $context = new TenantContext();
        $middleware = new TenantResolutionMiddleware($resolver, $context, $config);

        $request = new Request(
            method: Method::GET,
            uri: '/test',
            path: '/test',
            queryString: '',
            headers: new HeaderBag(['X-Tenant-ID' => 'acme']),
            body: '',
        );

        $capturedRequest = null;
        $response = $middleware->process($request, function (Request $req) use (&$capturedRequest): Response {
            $capturedRequest = $req;
            return new Response(body: 'ok');
        });

        self::assertTrue($context->isResolved());
        self::assertSame('acme', $context->get()->id);
        self::assertSame('Acme Corp', $context->get()->name);
        self::assertSame(['plan' => 'enterprise'], $context->get()->metadata);
        self::assertNotNull($capturedRequest);
        $tenant = $capturedRequest->attribute('_tenant');
        self::assertInstanceOf(Tenant::class, $tenant);
        self::assertSame('acme', $tenant->id);
    }

    #[Test]
    public function headerResolverResolvesFromXTenantIdHeader(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            headerName: 'X-Tenant-ID',
            tenants: [
                'acme' => ['name' => 'Acme Corp'],
                'globex' => ['name' => 'Globex Corp'],
            ],
        );

        $resolver = new HeaderTenantResolver($config);
        $context = new TenantContext();
        $middleware = new TenantResolutionMiddleware($resolver, $context, $config);

        $request = new Request(
            method: Method::GET,
            uri: '/api/data',
            path: '/api/data',
            queryString: '',
            headers: new HeaderBag(['X-Tenant-ID' => 'globex']),
            body: '',
        );

        $middleware->process($request, static fn(Request $req): Response => new Response(body: 'ok'));

        self::assertTrue($context->isResolved());
        self::assertSame('globex', $context->get()->id);
        self::assertSame('Globex Corp', $context->get()->name);
    }

    #[Test]
    public function subdomainResolverResolvesFromHost(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Subdomain,
            subdomainSuffix: '.example.com',
            tenants: [
                'acme' => ['name' => 'Acme Corp', 'metadata' => ['plan' => 'enterprise']],
            ],
        );

        $resolver = new SubdomainTenantResolver($config);
        $context = new TenantContext();
        $middleware = new TenantResolutionMiddleware($resolver, $context, $config);

        $request = new Request(
            method: Method::GET,
            uri: '/dashboard',
            path: '/dashboard',
            queryString: '',
            headers: new HeaderBag(['Host' => 'acme.example.com']),
            body: '',
        );

        $middleware->process($request, static fn(Request $req): Response => new Response(body: 'ok'));

        self::assertTrue($context->isResolved());
        self::assertSame('acme', $context->get()->id);
        self::assertSame('Acme Corp', $context->get()->name);
    }

    #[Test]
    public function defaultTenantFallbackWhenNoTenantInRequest(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            headerName: 'X-Tenant-ID',
            tenants: [
                'default-co' => ['name' => 'Default Company'],
                'acme' => ['name' => 'Acme Corp'],
            ],
            defaultTenant: 'default-co',
        );

        $resolver = new HeaderTenantResolver($config);
        $context = new TenantContext();
        $middleware = new TenantResolutionMiddleware($resolver, $context, $config);

        // Request without any tenant header
        $request = new Request(
            method: Method::GET,
            uri: '/home',
            path: '/home',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
        );

        $middleware->process($request, static fn(Request $req): Response => new Response(body: 'ok'));

        self::assertTrue($context->isResolved());
        self::assertSame('default-co', $context->get()->id);
        self::assertSame('Default Company', $context->get()->name);
    }

    #[Test]
    public function tenantAwareConnectionManagerReturnsCorrectPrefixForResolvedTenant(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            headerName: 'X-Tenant-ID',
            tenants: ['acme' => ['name' => 'Acme Corp']],
            database: new TenantDatabaseConfig(strategy: TenantDatabaseStrategy::Prefix, prefixTemplate: 'tenant_{tenant_id}_'),
        );

        $innerManager = new class implements ConnectionManagerInterface {
            public function connection(?string $name = null): ConnectionInterface
            {
                throw new RuntimeException('connection() should not be called in this test');
            }

            public function getDefaultConnectionName(): string
            {
                return 'default';
            }

            public function disconnect(?string $name = null): void {}
        };

        $context = new TenantContext();
        $context->set(Tenant::fromArray('acme', ['name' => 'Acme Corp']));

        $manager = new TenantAwareConnectionManager($innerManager, $context, $config);

        self::assertSame('tenant_acme_', $manager->getTablePrefix());
        self::assertSame('default', $manager->getDefaultConnectionName());
    }
}
