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
use Pulsar\Extension\Admin\Internal\Middleware\AdminCsrfMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(AdminCsrfMiddleware::class)]
final class AdminCsrfMiddlewareTest extends TestCase
{
    #[Test]
    public function canBeConstructed(): void
    {
        $config = AdminConfig::fromArray(['enabled' => true]);

        $middleware = new AdminCsrfMiddleware($config);

        self::assertInstanceOf(AdminCsrfMiddleware::class, $middleware);
    }

    /**
     * F33.4: the canonical happy path — header-based token from
     * a SPA / fetch / XHR client, verified against the
     * session-attached token.
     */
    #[Test]
    public function acceptsTokenFromHeader(): void
    {
        $config = AdminConfig::fromArray(['enabled' => true]);
        $middleware = new AdminCsrfMiddleware($config);

        $token = 'a-csrf-token-value-1234567890';
        $request = new ServerRequest(method: 'POST', uri: '/admin/users', headers: [
            'X-CSRF-Token' => $token,
        ]);
        $request = $request->withAttribute('csrf_token', $token);

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * F33.4: HTML-form fallback — `<form>` POSTs cannot set a
     * header, so the token must also be accepted from the
     * `_csrf_token` body field. Enables a noscript admin path.
     */
    #[Test]
    public function acceptsTokenFromPostFieldFallback(): void
    {
        $config = AdminConfig::fromArray(['enabled' => true]);
        $middleware = new AdminCsrfMiddleware($config);

        $token = 'a-csrf-token-value-1234567890';
        $request = new ServerRequest(method: 'POST', uri: '/admin/users', parsedBody: [
            '_csrf_token' => $token,
            'name' => 'Alice',
        ]);
        $request = $request->withAttribute('csrf_token', $token);

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * F33.4: a request without either form of the token gets
     * 403 — never a silent pass.
     */
    #[Test]
    public function rejectsMutationWithNoToken(): void
    {
        $config = AdminConfig::fromArray(['enabled' => true]);
        $middleware = new AdminCsrfMiddleware($config);

        $request = new ServerRequest(method: 'POST', uri: '/admin/users');
        $request = $request->withAttribute('csrf_token', 'expected-token');

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * F33.4: when both the header and the POST field are
     * present, the header value wins. This pins the precedence
     * so a client that sends both for resilience does not get
     * surprised when one is mismatched.
     */
    #[Test]
    public function headerWinsOverPostFieldWhenBothPresent(): void
    {
        $config = AdminConfig::fromArray(['enabled' => true]);
        $middleware = new AdminCsrfMiddleware($config);

        $sessionToken = 'session-token';
        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/users',
            headers: ['X-CSRF-Token' => $sessionToken],
            parsedBody: ['_csrf_token' => 'WRONG-VALUE'],
        );
        $request = $request->withAttribute('csrf_token', $sessionToken);

        $response = $middleware->process($request, $this->okHandler());

        self::assertSame(200, $response->getStatusCode(), 'header value should win and match');
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
