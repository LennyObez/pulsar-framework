<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Internal\Middleware\AdminCspMiddleware;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

use function strlen;

#[CoversClass(AdminCspMiddleware::class)]
final class AdminCspMiddlewareTest extends TestCase
{
    private static function makeRequest(): Request
    {
        return new Request(
            method: Method::GET,
            uri: '/admin/dashboard',
            path: '/admin/dashboard',
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    #[Test]
    public function addsCspHeaderWithNonce(): void
    {
        $config = AdminConfig::fromArray([
            'enabled' => true,
            'security' => ['csp_nonce' => true],
        ]);
        $middleware = new AdminCspMiddleware($config);

        $next = static fn(Request $r): Response => new Response(body: 'ok');

        $response = $middleware->process(self::makeRequest(), $next);

        $csp = $response->headers->first('Content-Security-Policy');
        self::assertNotNull($csp);
        self::assertStringContainsString("'nonce-", $csp);
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    #[Test]
    public function addsCspHeaderWithoutNonce(): void
    {
        $config = AdminConfig::fromArray([
            'enabled' => true,
            'security' => ['csp_nonce' => false],
        ]);
        $middleware = new AdminCspMiddleware($config);

        $next = static fn(Request $r): Response => new Response(body: 'ok');

        $response = $middleware->process(self::makeRequest(), $next);

        $csp = $response->headers->first('Content-Security-Policy');
        self::assertNotNull($csp);
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringNotContainsString('nonce-', $csp);
    }

    #[Test]
    public function setsNonceAttributeOnRequest(): void
    {
        $config = AdminConfig::fromArray([
            'enabled' => true,
            'security' => ['csp_nonce' => true],
        ]);
        $middleware = new AdminCspMiddleware($config);

        $receivedNonce = null;
        $next = static function (Request $r) use (&$receivedNonce): Response {
            $receivedNonce = $r->attribute('csp_nonce');
            return new Response(body: 'ok');
        };

        $middleware->process(self::makeRequest(), $next);

        self::assertNotNull($receivedNonce);
        self::assertIsString($receivedNonce);
        self::assertSame(32, strlen($receivedNonce)); // 16 bytes = 32 hex chars
    }

    #[Test]
    public function doesNotSetNonceAttributeWhenDisabled(): void
    {
        $config = AdminConfig::fromArray([
            'enabled' => true,
            'security' => ['csp_nonce' => false],
        ]);
        $middleware = new AdminCspMiddleware($config);

        $receivedNonce = null;
        $next = static function (Request $r) use (&$receivedNonce): Response {
            $receivedNonce = $r->attribute('csp_nonce');
            return new Response(body: 'ok');
        };

        $middleware->process(self::makeRequest(), $next);

        self::assertNull($receivedNonce);
    }

    #[Test]
    public function cspIncludesStyleSrcUnsafeInline(): void
    {
        $config = AdminConfig::fromArray([
            'enabled' => true,
            'security' => ['csp_nonce' => true],
        ]);
        $middleware = new AdminCspMiddleware($config);

        $next = static fn(Request $r): Response => new Response(body: 'ok');

        $response = $middleware->process(self::makeRequest(), $next);

        $csp = $response->headers->first('Content-Security-Policy');
        self::assertNotNull($csp);
        self::assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
    }

    #[Test]
    public function cspIncludesBaseUriSelf(): void
    {
        $config = AdminConfig::fromArray([
            'enabled' => true,
            'security' => ['csp_nonce' => true],
        ]);
        $middleware = new AdminCspMiddleware($config);

        $next = static fn(Request $r): Response => new Response(body: 'ok');

        $response = $middleware->process(self::makeRequest(), $next);

        $csp = $response->headers->first('Content-Security-Policy');
        self::assertNotNull($csp);
        self::assertStringContainsString("base-uri 'self'", $csp);
        self::assertStringContainsString("form-action 'self'", $csp);
    }
}
