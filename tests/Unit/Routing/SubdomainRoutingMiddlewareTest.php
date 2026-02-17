<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Message\Uri;
use Pulsar\Routing\DomainContext;
use Pulsar\Routing\DomainResolverInterface;
use Pulsar\Routing\SubdomainRoutingMiddleware;

#[CoversClass(SubdomainRoutingMiddleware::class)]
final class SubdomainRoutingMiddlewareTest extends TestCase
{
    #[Test]
    public function attachesDomainContextToRequest(): void
    {
        $expectedContext = new DomainContext(
            domain: 'forum.example.com',
            subdomain: 'forum',
            extensionScopes: ['forum'],
            isDefault: false,
        );

        $resolver = $this->createStub(DomainResolverInterface::class);
        $resolver->method('resolve')->willReturn($expectedContext);

        $middleware = new SubdomainRoutingMiddleware($resolver);

        $capturedRequest = null;
        $handler = new class ($capturedRequest) implements RequestHandlerInterface {
            /** @param ServerRequestInterface|null $captured */
            public function __construct(public ?ServerRequestInterface &$captured) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;

                return new Response(200);
            }
        };

        $request = new ServerRequest('GET', new Uri(scheme: 'http', host: 'forum.example.com', path: '/'));
        $response = $middleware->process($request, $handler);

        self::assertNotNull($capturedRequest);
        self::assertSame(200, $response->getStatusCode());

        $attribute = $capturedRequest->getAttribute(SubdomainRoutingMiddleware::ATTRIBUTE);
        self::assertInstanceOf(DomainContext::class, $attribute);
        self::assertSame('forum.example.com', $attribute->domain);
        self::assertSame('forum', $attribute->subdomain);
        self::assertSame(['forum'], $attribute->extensionScopes);
        self::assertFalse($attribute->isDefault);
    }

    #[Test]
    public function defaultContextWhenNoSubdomainMappings(): void
    {
        $defaultContext = new DomainContext(
            domain: 'example.com',
            subdomain: '',
            extensionScopes: [],
            isDefault: true,
        );

        $resolver = $this->createStub(DomainResolverInterface::class);
        $resolver->method('resolve')->willReturn($defaultContext);

        $middleware = new SubdomainRoutingMiddleware($resolver);

        $capturedRequest = null;
        $handler = new class ($capturedRequest) implements RequestHandlerInterface {
            /** @param ServerRequestInterface|null $captured */
            public function __construct(public ?ServerRequestInterface &$captured) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;

                return new Response(200);
            }
        };

        $request = new ServerRequest('GET', new Uri(scheme: 'http', host: 'example.com', path: '/'));
        $middleware->process($request, $handler);

        self::assertNotNull($capturedRequest);

        $attribute = $capturedRequest->getAttribute(SubdomainRoutingMiddleware::ATTRIBUTE);
        self::assertInstanceOf(DomainContext::class, $attribute);
        self::assertTrue($attribute->isDefault);
    }

    #[Test]
    public function passesResponseFromHandler(): void
    {
        $resolver = $this->createStub(DomainResolverInterface::class);
        $resolver->method('resolve')->willReturn(
            new DomainContext('localhost', '', [], true),
        );

        $middleware = new SubdomainRoutingMiddleware($resolver);

        $expectedResponse = new Response(404);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $request = new ServerRequest('GET', new Uri(scheme: 'http', host: 'localhost', path: '/'));
        $response = $middleware->process($request, $handler);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function attributeConstant(): void
    {
        self::assertSame('pulsar.domain_context', SubdomainRoutingMiddleware::ATTRIBUTE);
    }
}
