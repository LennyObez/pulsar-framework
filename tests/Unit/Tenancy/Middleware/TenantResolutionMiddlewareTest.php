<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tenancy\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\TenancyConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
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

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test',
            headers: ['X-Tenant-ID' => 'acme'],
        );

        $response = new Response(statusCode: 200, body: 'ok');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $middleware->process($request, $handler);

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

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test',
            headers: ['X-Tenant-ID' => 'acme'],
        );

        $capturedRequest = null;
        $response = new Response(statusCode: 200, body: 'ok');
        $handler = new class ($response, $capturedRequest) implements RequestHandlerInterface {
            public ?ServerRequestInterface $capturedRequest = null;

            public function __construct(
                private ResponseInterface $response,
                ?ServerRequestInterface &$ref,
            ) {
                $this->capturedRequest = &$ref;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->capturedRequest = $request;

                return $this->response;
            }
        };

        $middleware->process($request, $handler);

        self::assertNotNull($handler->capturedRequest);
        $tenant = $handler->capturedRequest->getAttribute('_tenant');
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

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test',
        );

        $response = new Response(statusCode: 200, body: 'ok');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $middleware->process($request, $handler);

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

        $request = new ServerRequest(
            method: 'GET',
            uri: '/test',
        );

        $response = new Response(statusCode: 200, body: 'ok');
        $capturedRequest = null;
        $handler = new class ($response, $capturedRequest) implements RequestHandlerInterface {
            public ?ServerRequestInterface $capturedRequest = null;

            public function __construct(
                private ResponseInterface $response,
                ?ServerRequestInterface &$ref,
            ) {
                $this->capturedRequest = &$ref;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->capturedRequest = $request;

                return $this->response;
            }
        };

        $result = $middleware->process($request, $handler);

        self::assertFalse($context->isResolved());
        self::assertSame($response, $result);
        self::assertNotNull($handler->capturedRequest);
        self::assertNull($handler->capturedRequest->getAttribute('_tenant'));
    }
}
