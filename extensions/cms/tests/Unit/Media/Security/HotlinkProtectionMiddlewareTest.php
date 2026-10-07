<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Cms\Media\Security\HotlinkProtectionMiddleware;

#[CoversClass(HotlinkProtectionMiddleware::class)]
final class HotlinkProtectionMiddlewareTest extends TestCase
{
    #[Test]
    public function disabled_middleware_passes_through(): void
    {
        $middleware = new HotlinkProtectionMiddleware(
            allowedDomains: [],
            enabled: false,
        );

        $request = $this->createRequest('https://evil.com/page');
        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createHandler($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function allows_requests_with_no_referer(): void
    {
        $middleware = new HotlinkProtectionMiddleware(
            allowedDomains: ['example.com'],
            enabled: true,
        );

        $request = $this->createRequest('');
        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createHandler($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function allows_same_origin_requests(): void
    {
        $middleware = new HotlinkProtectionMiddleware(
            allowedDomains: [],
            enabled: true,
        );

        $request = $this->createRequest(
            'https://mysite.com/gallery',
            'mysite.com',
        );
        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createHandler($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function allows_requests_from_allowed_domains(): void
    {
        $middleware = new HotlinkProtectionMiddleware(
            allowedDomains: ['partner.com', 'cdn.example.com'],
            enabled: true,
        );

        $request = $this->createRequest(
            'https://partner.com/article',
            'mysite.com',
        );
        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createHandler($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function blocks_requests_from_unauthorized_domains(): void
    {
        $middleware = new HotlinkProtectionMiddleware(
            allowedDomains: ['partner.com'],
            enabled: true,
        );

        $request = $this->createRequest(
            'https://evil.com/steal-images',
            'mysite.com',
        );
        $handler = $this->createHandler($this->createStub(ResponseInterface::class));

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function blocked_response_contains_no_store_cache_header(): void
    {
        $middleware = new HotlinkProtectionMiddleware(
            allowedDomains: [],
            enabled: true,
        );

        $request = $this->createRequest(
            'https://evil.com/page',
            'mysite.com',
        );
        $handler = $this->createHandler($this->createStub(ResponseInterface::class));

        $response = $middleware->process($request, $handler);

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function supports_wildcard_subdomain_matching(): void
    {
        $middleware = new HotlinkProtectionMiddleware(
            allowedDomains: ['*.example.com'],
            enabled: true,
        );

        $request = $this->createRequest(
            'https://blog.example.com/article',
            'mysite.com',
        );
        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createHandler($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function wildcard_does_not_match_bare_domain(): void
    {
        $middleware = new HotlinkProtectionMiddleware(
            allowedDomains: ['*.example.com'],
            enabled: true,
        );

        // "example.com" does not end with ".example.com"; should be blocked
        $request = $this->createRequest(
            'https://example.com/page',
            'mysite.com',
        );
        $handler = $this->createHandler($this->createStub(ResponseInterface::class));

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function case_insensitive_domain_matching(): void
    {
        $middleware = new HotlinkProtectionMiddleware(
            allowedDomains: ['Partner.COM'],
            enabled: true,
        );

        $request = $this->createRequest(
            'https://partner.com/page',
            'mysite.com',
        );
        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createHandler($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function handles_referer_with_no_parseable_host(): void
    {
        $middleware = new HotlinkProtectionMiddleware(
            allowedDomains: [],
            enabled: true,
        );

        $request = $this->createRequest('not-a-valid-url');
        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createHandler($expectedResponse);

        $response = $middleware->process($request, $handler);

        // Unparseable referer is allowed through (graceful handling)
        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    #[DataProvider('blockedRefererProvider')]
    public function blocks_various_unauthorized_referers(string $referer): void
    {
        $middleware = new HotlinkProtectionMiddleware(
            allowedDomains: ['trusted.com'],
            enabled: true,
        );

        $request = $this->createRequest($referer, 'mysite.com');
        $handler = $this->createHandler($this->createStub(ResponseInterface::class));

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedRefererProvider(): iterable
    {
        yield 'random site' => ['https://hotlinker.com/post'];
        yield 'similar domain' => ['https://trusted.com.evil.com/page'];
        yield 'subdomain of untrusted' => ['https://sub.untrusted.com/page'];
    }

    private function createRequest(string $referer, string $requestHost = 'mysite.com'): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getHost')->willReturn($requestHost);
        $uri->method('getScheme')->willReturn('https');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name): string => match (strtolower($name)) {
                'referer' => $referer,
                default => '',
            },
        );
        $request->method('getUri')->willReturn($uri);

        return $request;
    }

    private function createHandler(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }
}
