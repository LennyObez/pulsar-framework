<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Internal\Middleware\AdminCspMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

use function strlen;

#[CoversClass(AdminCspMiddleware::class)]
final class AdminCspMiddlewareTest extends TestCase
{
    private static function makeRequest(): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/admin/dashboard',
        );
    }

    private static function makeHandler(Response $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly Response $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    #[Test]
    public function addsCspHeaderWithNonce(): void
    {
        $config = AdminConfig::fromArray([
            'enabled' => true,
            'security' => ['csp_nonce' => true],
        ]);
        $middleware = new AdminCspMiddleware($config);

        $handler = self::makeHandler(new Response(body: 'ok'));

        $response = $middleware->process(self::makeRequest(), $handler);

        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertNotEmpty($csp);
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

        $handler = self::makeHandler(new Response(body: 'ok'));

        $response = $middleware->process(self::makeRequest(), $handler);

        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertNotEmpty($csp);
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
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$receivedNonce): ResponseInterface {
                $receivedNonce = $req->getAttribute('csp_nonce');
                return new Response(body: 'ok');
            },
        );

        $middleware->process(self::makeRequest(), $handler);

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
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$receivedNonce): ResponseInterface {
                $receivedNonce = $req->getAttribute('csp_nonce');
                return new Response(body: 'ok');
            },
        );

        $middleware->process(self::makeRequest(), $handler);

        self::assertNull($receivedNonce);
    }

    /**
     * F33.5: when csp_nonce is enabled the style-src binds to the
     * generated nonce, not `'unsafe-inline'`. The previous
     * assertion was the pre-F33.5 less-strict shape.
     */
    #[Test]
    public function cspBindsStyleSrcToNonceWhenEnabled(): void
    {
        $config = AdminConfig::fromArray([
            'enabled' => true,
            'security' => ['csp_nonce' => true],
        ]);
        $middleware = new AdminCspMiddleware($config);

        $handler = self::makeHandler(new Response(body: 'ok'));

        $response = $middleware->process(self::makeRequest(), $handler);

        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertNotEmpty($csp);
        self::assertMatchesRegularExpression(
            "/style-src 'self' 'nonce-[a-f0-9]+'/",
            $csp,
        );
        self::assertStringNotContainsString("'unsafe-inline'", $csp);
    }

    #[Test]
    public function cspIncludesBaseUriSelf(): void
    {
        $config = AdminConfig::fromArray([
            'enabled' => true,
            'security' => ['csp_nonce' => true],
        ]);
        $middleware = new AdminCspMiddleware($config);

        $handler = self::makeHandler(new Response(body: 'ok'));

        $response = $middleware->process(self::makeRequest(), $handler);

        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertNotEmpty($csp);
        self::assertStringContainsString("base-uri 'self'", $csp);
        self::assertStringContainsString("form-action 'self'", $csp);
    }
}
