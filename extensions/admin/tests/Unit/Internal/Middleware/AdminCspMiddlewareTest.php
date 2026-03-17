<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Middleware;

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

#[CoversClass(AdminCspMiddleware::class)]
final class AdminCspMiddlewareTest extends TestCase
{
    #[Test]
    public function can_be_constructed(): void
    {
        $config = AdminConfig::fromArray(['enabled' => true]);

        $middleware = new AdminCspMiddleware($config);

        self::assertInstanceOf(AdminCspMiddleware::class, $middleware);
    }

    /**
     * F33.5: when csp_nonce is enabled the middleware MUST emit
     * `style-src 'self' 'nonce-<value>'` instead of
     * `'self' 'unsafe-inline'`. The nonce branch is the
     * structural defence against CSS-attribute-injection XSS,
     * so this regression test pins the contract.
     */
    #[Test]
    public function nonceModeBindsStyleSrcToNonce(): void
    {
        $config = AdminConfig::fromArray([
            'enabled' => true,
            'security' => ['csp_nonce' => true],
        ]);
        $middleware = new AdminCspMiddleware($config);

        $request = new ServerRequest(method: 'GET', uri: '/admin/users');
        $response = $middleware->process($request, $this->okHandler());

        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertMatchesRegularExpression(
            "/style-src 'self' 'nonce-[a-f0-9]+'/",
            $csp,
            'csp_nonce mode must emit a nonce-bound style-src',
        );
        self::assertStringNotContainsString(
            "'unsafe-inline'",
            $csp,
            'csp_nonce mode must NOT emit unsafe-inline anywhere',
        );
    }

    /**
     * F33.5: with csp_nonce explicitly disabled the middleware
     * falls back to `'self' 'unsafe-inline'` so the legacy
     * admin template's inline `<style>` blocks render. F33.12
     * tracks the bundled-asset migration that removes inline-
     * style dependency entirely; until then this branch is the
     * documented less-strict fallback.
     */
    #[Test]
    public function nonceDisabledFallsBackToUnsafeInline(): void
    {
        $config = AdminConfig::fromArray([
            'enabled' => true,
            'security' => ['csp_nonce' => false],
        ]);
        $middleware = new AdminCspMiddleware($config);

        $request = new ServerRequest(method: 'GET', uri: '/admin/users');
        $response = $middleware->process($request, $this->okHandler());

        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::text('OK');
            }
        };
    }
}
