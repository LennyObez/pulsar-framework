<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\Middleware\SecurityHeadersMiddleware;

#[CoversClass(SecurityHeadersMiddleware::class)]
final class SecurityHeadersMiddlewareTest extends TestCase
{
    private function createRequest(): Request
    {
        return new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    #[Test]
    public function addsConfiguredHeaders(): void
    {
        $config = new SecurityHeadersConfig(headers: [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ]);

        $middleware = new SecurityHeadersMiddleware($config);

        $handler = fn(Request $r): Response => Response::text('OK');
        $response = $middleware->process($this->createRequest(), $handler);

        self::assertSame('nosniff', $response->headers->first('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->first('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->first('Referrer-Policy'));
    }

    #[Test]
    public function emptyConfigAddsNoHeaders(): void
    {
        $config = new SecurityHeadersConfig(headers: []);
        $middleware = new SecurityHeadersMiddleware($config);

        $handler = fn(Request $r): Response => Response::text('OK');
        $response = $middleware->process($this->createRequest(), $handler);

        self::assertSame(ResponseStatus::OK, $response->status);
        // Only the Content-Type from Response::text()
        self::assertSame('text/plain; charset=utf-8', $response->headers->first('Content-Type'));
    }

    #[Test]
    public function preservesExistingResponseHeaders(): void
    {
        $config = new SecurityHeadersConfig(headers: [
            'X-Frame-Options' => 'DENY',
        ]);

        $middleware = new SecurityHeadersMiddleware($config);

        $handler = fn(Request $r): Response => Response::json(['status' => 'ok']);
        $response = $middleware->process($this->createRequest(), $handler);

        // Original Content-Type preserved
        self::assertStringContainsString('application/json', $response->headers->first('Content-Type') ?? '');
        // Security header added
        self::assertSame('DENY', $response->headers->first('X-Frame-Options'));
    }

    #[Test]
    public function overridesHeaderIfAlreadySet(): void
    {
        $config = new SecurityHeadersConfig(headers: [
            'X-Frame-Options' => 'DENY',
        ]);

        $middleware = new SecurityHeadersMiddleware($config);

        $handler = fn(Request $r): Response => Response::text('OK')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN');

        $response = $middleware->process($this->createRequest(), $handler);

        // Config value should override
        self::assertSame('DENY', $response->headers->first('X-Frame-Options'));
    }
}
