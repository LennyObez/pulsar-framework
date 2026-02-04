<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\TenancyConfig;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Tenancy\Middleware\TenantResolutionMiddleware;
use Pulsar\Tenancy\Resolver\HeaderTenantResolver;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantContext;
use Pulsar\Tenancy\TenantResolverStrategy;

#[CoversClass(TenantResolutionMiddleware::class)]
final class TenantResolutionMiddlewareTest extends TestCase
{
    #[Test]
    public function setsTenantOnContextWhenResolved(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            headerName: 'X-Tenant-ID',
            tenants: ['acme' => ['name' => 'Acme Corp']],
        );

        $context = new TenantContext();
        $resolver = new HeaderTenantResolver($config);
        $middleware = new TenantResolutionMiddleware($resolver, $context, $config);

        $request = new Request(
            method: Method::GET,
            uri: '/test',
            path: '/test',
            queryString: '',
            headers: new HeaderBag(['X-Tenant-ID' => ['acme']]),
            body: '',
        );

        $response = new Response(body: 'ok');
        $next = function (Request $r) use ($response): Response {
            return $response;
        };

        $middleware->process($request, $next);

        self::assertTrue($context->isResolved());
        self::assertSame('acme', $context->get()->id);
    }

    #[Test]
    public function addsTenantAttributeToRequest(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            headerName: 'X-Tenant-ID',
            tenants: ['acme' => ['name' => 'Acme Corp']],
        );

        $context = new TenantContext();
        $resolver = new HeaderTenantResolver($config);
        $middleware = new TenantResolutionMiddleware($resolver, $context, $config);

        $request = new Request(
            method: Method::GET,
            uri: '/test',
            path: '/test',
            queryString: '',
            headers: new HeaderBag(['X-Tenant-ID' => ['acme']]),
            body: '',
        );

        $capturedRequest = null;
        $response = new Response(body: 'ok');
        $next = function (Request $r) use ($response, &$capturedRequest): Response {
            $capturedRequest = $r;

            return $response;
        };

        $middleware->process($request, $next);

        self::assertNotNull($capturedRequest);
        $tenant = $capturedRequest->attribute('_tenant');
        self::assertInstanceOf(Tenant::class, $tenant);
        self::assertSame('acme', $tenant->id);
    }

    #[Test]
    public function usesDefaultTenantWhenResolverReturnsNullAndDefaultConfigured(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            headerName: 'X-Tenant-ID',
            defaultTenant: 'fallback',
            tenants: [
                'acme' => ['name' => 'Acme Corp'],
                'fallback' => ['name' => 'Fallback Tenant'],
            ],
        );

        $context = new TenantContext();
        $resolver = new HeaderTenantResolver($config);
        $middleware = new TenantResolutionMiddleware($resolver, $context, $config);

        $request = new Request(
            method: Method::GET,
            uri: '/test',
            path: '/test',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );

        $response = new Response(body: 'ok');
        $next = function (Request $r) use ($response): Response {
            return $response;
        };

        $middleware->process($request, $next);

        self::assertTrue($context->isResolved());
        self::assertSame('fallback', $context->get()->id);
        self::assertSame('Fallback Tenant', $context->get()->name);
    }

    #[Test]
    public function passesThroughWhenNoTenantResolvedAndNoDefault(): void
    {
        $config = new TenancyConfig(
            enabled: true,
            resolver: TenantResolverStrategy::Header,
            headerName: 'X-Tenant-ID',
            tenants: ['acme' => ['name' => 'Acme Corp']],
        );

        $context = new TenantContext();
        $resolver = new HeaderTenantResolver($config);
        $middleware = new TenantResolutionMiddleware($resolver, $context, $config);

        $request = new Request(
            method: Method::GET,
            uri: '/test',
            path: '/test',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );

        $response = new Response(body: 'ok');
        $capturedRequest = null;
        $next = function (Request $r) use ($response, &$capturedRequest): Response {
            $capturedRequest = $r;

            return $response;
        };

        $result = $middleware->process($request, $next);

        self::assertFalse($context->isResolved());
        self::assertSame($response, $result);
        self::assertNotNull($capturedRequest);
        self::assertNull($capturedRequest->attribute('_tenant'));
    }
}
