<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Tenancy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\TenancyConfig;
use Pulsar\Config\TenantDatabaseConfig;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
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

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test',
            headers: ['X-Tenant-ID' => 'acme'],
        );

        $capturedRequest = null;
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedRequest): ResponseInterface {
                $capturedRequest = $req;
                return new Response(body: 'ok');
            },
        );

        $response = $middleware->process($request, $handler);

        self::assertTrue($context->isResolved());
        self::assertSame('acme', $context->get()->id);
        self::assertSame('Acme Corp', $context->get()->name);
        self::assertSame(['plan' => 'enterprise'], $context->get()->metadata);
        self::assertNotNull($capturedRequest);
        $tenant = $capturedRequest->getAttribute('_tenant');
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

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/data',
            headers: ['X-Tenant-ID' => 'globex'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(body: 'ok'));

        $middleware->process($request, $handler);

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

        $request = new ServerRequest(
            method: 'GET',
            uri: '/dashboard',
            headers: ['Host' => 'acme.example.com'],
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(body: 'ok'));

        $middleware->process($request, $handler);

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
        $request = new ServerRequest(
            method: 'GET',
            uri: '/home',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(body: 'ok'));

        $middleware->process($request, $handler);

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
