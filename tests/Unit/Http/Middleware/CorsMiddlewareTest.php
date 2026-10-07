<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\CorsConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\CorsMiddleware;

#[CoversClass(CorsMiddleware::class)]
final class CorsMiddlewareTest extends TestCase
{
    private function okHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        return $handler;
    }

    /**
     * @param array<string, list<string>|string> $headers
     */
    private function createRequest(
        string $method = 'GET',
        string $origin = '',
        array $headers = [],
    ): ServerRequest {
        if ($origin !== '') {
            $headers['Origin'] = $origin;
        }

        return new ServerRequest(
            method: $method,
            uri: '/api/data',
            headers: $headers,
        );
    }

    #[Test]
    public function nonCorsRequestPassesThroughWithoutHeaders(): void
    {
        $config = new CorsConfig(enabled: true, allowedOrigins: ['*']);
        $middleware = new CorsMiddleware($config);

        $response = $middleware->process($this->createRequest(), $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function corsRequestAddsAllowOriginHeader(): void
    {
        $config = new CorsConfig(
            enabled: true,
            allowedOrigins: ['https://example.com'],
        );
        $middleware = new CorsMiddleware($config);

        $request = $this->createRequest(origin: 'https://example.com');
        $response = $middleware->process($request, $this->okHandler());

        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('Origin', $response->getHeaderLine('Vary'));
    }

    #[Test]
    public function wildcardOriginUsesLiteralStar(): void
    {
        $config = new CorsConfig(enabled: true, allowedOrigins: ['*']);
        $middleware = new CorsMiddleware($config);

        $request = $this->createRequest(origin: 'https://any.com');
        $response = $middleware->process($request, $this->okHandler());

        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Vary'));
    }

    #[Test]
    public function disallowedOriginGetsNoHeaders(): void
    {
        $config = new CorsConfig(
            enabled: true,
            allowedOrigins: ['https://example.com'],
        );
        $middleware = new CorsMiddleware($config);

        $request = $this->createRequest(origin: 'https://evil.com');
        $response = $middleware->process($request, $this->okHandler());

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function preflightReturns204WithCorsHeaders(): void
    {
        $config = new CorsConfig(
            enabled: true,
            allowedOrigins: ['https://example.com'],
            allowedMethods: ['GET', 'POST'],
            allowedHeaders: ['Content-Type', 'Authorization'],
            maxAge: 3600,
        );
        $middleware = new CorsMiddleware($config);

        $request = $this->createRequest(
            method: 'OPTIONS',
            origin: 'https://example.com',
            headers: ['Access-Control-Request-Method' => 'POST'],
        );

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('3600', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    #[Test]
    public function credentialsHeaderSetWhenConfigured(): void
    {
        $config = new CorsConfig(
            enabled: true,
            allowedOrigins: ['https://example.com'],
            allowCredentials: true,
        );
        $middleware = new CorsMiddleware($config);

        $request = $this->createRequest(origin: 'https://example.com');
        $response = $middleware->process($request, $this->okHandler());

        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    #[Test]
    public function exposedHeadersSetWhenConfigured(): void
    {
        $config = new CorsConfig(
            enabled: true,
            allowedOrigins: ['https://example.com'],
            exposedHeaders: ['X-Request-Id', 'X-Total-Count'],
        );
        $middleware = new CorsMiddleware($config);

        $request = $this->createRequest(origin: 'https://example.com');
        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(
            'X-Request-Id, X-Total-Count',
            $response->getHeaderLine('Access-Control-Expose-Headers'),
        );
    }

    #[Test]
    public function preflightWithoutRequestMethodHeaderIsNotPreflight(): void
    {
        $config = new CorsConfig(
            enabled: true,
            allowedOrigins: ['https://example.com'],
        );
        $middleware = new CorsMiddleware($config);

        $request = $this->createRequest(
            method: 'OPTIONS',
            origin: 'https://example.com',
        );

        $response = $middleware->process($request, $this->okHandler());

        // Should be treated as a regular CORS request, not preflight
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
