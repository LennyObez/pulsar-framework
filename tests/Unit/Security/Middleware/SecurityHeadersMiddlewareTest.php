<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CrossOriginConfig;
use Pulsar\Config\CspConfig;
use Pulsar\Config\HstsConfig;
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
    /**
     * @param array<string, mixed> $server
     * @param array<string, list<string>|string> $headers
     */
    private function createRequest(array $server = [], array $headers = []): Request
    {
        return new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag($headers),
            body: '',
            server: $server,
        );
    }

    private function createHttpsRequest(): Request
    {
        return $this->createRequest(server: ['HTTPS' => 'on']);
    }

    private function createForwardedHttpsRequest(): Request
    {
        return $this->createRequest(headers: ['X-Forwarded-Proto' => 'https']);
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
    public function emptyConfigAppliesMinimumDefaults(): void
    {
        $config = new SecurityHeadersConfig(headers: []);
        $middleware = new SecurityHeadersMiddleware($config);

        $handler = fn(Request $r): Response => Response::text('OK');
        $response = $middleware->process($this->createRequest(), $handler);

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('nosniff', $response->headers->first('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->first('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->first('Referrer-Policy'));
        self::assertSame('0', $response->headers->first('X-XSS-Protection'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $response->headers->first('Permissions-Policy'));
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
    public function userHeadersOverrideMinimumDefaults(): void
    {
        $config = new SecurityHeadersConfig(headers: [
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);

        $middleware = new SecurityHeadersMiddleware($config);

        $handler = fn(Request $r): Response => Response::text('OK');
        $response = $middleware->process($this->createRequest(), $handler);

        // User override takes precedence
        self::assertSame('SAMEORIGIN', $response->headers->first('X-Frame-Options'));
        // Other minimums still present
        self::assertSame('nosniff', $response->headers->first('X-Content-Type-Options'));
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

    #[Test]
    public function hstsEmittedOnlyForHttpsRequests(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            hsts: new HstsConfig(enabled: true, maxAge: 31536000, includeSubDomains: true),
        );

        $middleware = new SecurityHeadersMiddleware($config);
        $handler = fn(Request $r): Response => Response::text('OK');

        $response = $middleware->process($this->createHttpsRequest(), $handler);

        self::assertSame(
            'max-age=31536000; includeSubDomains',
            $response->headers->first('Strict-Transport-Security'),
        );
    }

    #[Test]
    public function hstsNotEmittedForHttpRequests(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            hsts: new HstsConfig(enabled: true, maxAge: 31536000),
        );

        $middleware = new SecurityHeadersMiddleware($config);
        $handler = fn(Request $r): Response => Response::text('OK');

        $response = $middleware->process($this->createRequest(), $handler);

        self::assertNull($response->headers->first('Strict-Transport-Security'));
    }

    #[Test]
    public function hstsEmittedWhenXForwardedProtoIsHttps(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            hsts: new HstsConfig(enabled: true, maxAge: 63072000, includeSubDomains: true, preload: true),
        );

        $middleware = new SecurityHeadersMiddleware($config);
        $handler = fn(Request $r): Response => Response::text('OK');

        $response = $middleware->process($this->createForwardedHttpsRequest(), $handler);

        self::assertSame(
            'max-age=63072000; includeSubDomains; preload',
            $response->headers->first('Strict-Transport-Security'),
        );
    }

    #[Test]
    public function hstsNotEmittedWhenDisabled(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            hsts: new HstsConfig(enabled: false),
        );

        $middleware = new SecurityHeadersMiddleware($config);
        $handler = fn(Request $r): Response => Response::text('OK');

        $response = $middleware->process($this->createHttpsRequest(), $handler);

        self::assertNull($response->headers->first('Strict-Transport-Security'));
    }

    #[Test]
    public function cspHeaderPresentInResponse(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            csp: new CspConfig(enabled: true, defaultSrc: "'self'", scriptSrc: "'self' 'unsafe-inline'"),
        );

        $middleware = new SecurityHeadersMiddleware($config);
        $handler = fn(Request $r): Response => Response::text('OK');

        $response = $middleware->process($this->createRequest(), $handler);

        $csp = $response->headers->first('Content-Security-Policy');
        self::assertNotNull($csp);
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    #[Test]
    public function coepAbsentByDefault(): void
    {
        $config = new SecurityHeadersConfig(headers: []);

        $middleware = new SecurityHeadersMiddleware($config);
        $handler = fn(Request $r): Response => Response::text('OK');

        $response = $middleware->process($this->createRequest(), $handler);

        self::assertNull($response->headers->first('Cross-Origin-Embedder-Policy'));
    }

    #[Test]
    public function reportOnlyCspMode(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            csp: new CspConfig(enabled: true, reportOnly: true, defaultSrc: "'self'"),
        );

        $middleware = new SecurityHeadersMiddleware($config);
        $handler = fn(Request $r): Response => Response::text('OK');

        $response = $middleware->process($this->createRequest(), $handler);

        self::assertNotNull($response->headers->first('Content-Security-Policy-Report-Only'));
        self::assertNull($response->headers->first('Content-Security-Policy'));
    }

    #[Test]
    public function crossOriginHeadersPresent(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            crossOrigin: new CrossOriginConfig(
                openerPolicy: 'same-origin',
                embedderPolicy: 'require-corp',
                resourcePolicy: 'same-origin',
            ),
        );

        $middleware = new SecurityHeadersMiddleware($config);
        $handler = fn(Request $r): Response => Response::text('OK');

        $response = $middleware->process($this->createRequest(), $handler);

        self::assertSame('same-origin', $response->headers->first('Cross-Origin-Opener-Policy'));
        self::assertSame('require-corp', $response->headers->first('Cross-Origin-Embedder-Policy'));
        self::assertSame('same-origin', $response->headers->first('Cross-Origin-Resource-Policy'));
    }
}
