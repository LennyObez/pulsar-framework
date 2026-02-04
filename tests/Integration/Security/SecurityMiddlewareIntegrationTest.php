<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CsrfConfig;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\Csrf\CsrfMiddleware;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;
use Pulsar\Security\Middleware\SecurityHeadersMiddleware;

/**
 * Integration tests for the full security middleware stack.
 */
#[CoversClass(SecurityHeadersMiddleware::class)]
#[CoversClass(CsrfMiddleware::class)]
final class SecurityMiddlewareIntegrationTest extends TestCase
{
    private CsrfConfig $csrfConfig;
    private SecurityHeadersConfig $headersConfig;

    protected function setUp(): void
    {
        $this->csrfConfig = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
        );

        $this->headersConfig = new SecurityHeadersConfig(headers: [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ]);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $post
     */
    private function createRequest(
        Method $method = Method::GET,
        string $path = '/',
        array $headers = [],
        array $post = [],
    ): Request {
        return new Request(
            method: $method,
            uri: $path,
            path: $path,
            queryString: '',
            headers: new HeaderBag($headers),
            body: '',
            post: $post,
        );
    }

    #[Test]
    public function securityHeadersAppliedToEveryResponse(): void
    {
        $middleware = new SecurityHeadersMiddleware($this->headersConfig);

        $handler = fn(Request $r): Response => Response::json(['status' => 'ok']);

        $response = $middleware->process($this->createRequest(), $handler);

        self::assertSame('nosniff', $response->headers->first('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->first('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->first('Referrer-Policy'));
        self::assertSame(ResponseStatus::OK, $response->status);
    }

    #[Test]
    public function csrfMiddlewareAllowsGetThroughThenBlocksPost(): void
    {
        $tokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $tokenManager->method('validate')->willReturn(false);

        $middleware = new CsrfMiddleware($tokenManager, $this->csrfConfig);
        $handler = fn(Request $r): Response => Response::text('OK');

        // GET passes through
        $getResponse = $middleware->process(
            $this->createRequest(Method::GET),
            $handler,
        );
        self::assertSame(ResponseStatus::OK, $getResponse->status);

        // POST without token returns 403
        $postResponse = $middleware->process(
            $this->createRequest(Method::POST, '/submit'),
            $handler,
        );
        self::assertSame(ResponseStatus::Forbidden, $postResponse->status);
    }

    #[Test]
    public function csrfMiddlewareAcceptsValidTokenInHeader(): void
    {
        $token = bin2hex(random_bytes(32));

        $tokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $tokenManager->method('validate')
            ->with($token)
            ->willReturn(true);

        $middleware = new CsrfMiddleware($tokenManager, $this->csrfConfig);
        $handler = fn(Request $r): Response => Response::text('Created');

        $response = $middleware->process(
            $this->createRequest(
                Method::POST,
                '/submit',
                headers: ['X-CSRF-Token' => $token],
            ),
            $handler,
        );

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('Created', $response->body);
    }

    #[Test]
    public function csrfMiddlewareAcceptsValidTokenInPostField(): void
    {
        $token = bin2hex(random_bytes(32));

        $tokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $tokenManager->method('validate')
            ->with($token)
            ->willReturn(true);

        $middleware = new CsrfMiddleware($tokenManager, $this->csrfConfig);
        $handler = fn(Request $r): Response => Response::text('Created');

        $response = $middleware->process(
            $this->createRequest(
                Method::POST,
                '/submit',
                post: ['_csrf_token' => $token],
            ),
            $handler,
        );

        self::assertSame(ResponseStatus::OK, $response->status);
    }

    #[Test]
    public function combinedMiddlewareStackAppliesAllHeaders(): void
    {
        $token = bin2hex(random_bytes(32));

        $tokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $tokenManager->method('validate')->with($token)->willReturn(true);

        $csrfMiddleware = new CsrfMiddleware($tokenManager, $this->csrfConfig);
        $headersMiddleware = new SecurityHeadersMiddleware($this->headersConfig);

        $handler = fn(Request $r): Response => Response::json(['data' => 'value']);

        // Build pipeline: security headers wraps CSRF which wraps handler
        $pipeline = fn(Request $r): Response => $headersMiddleware->process(
            $r,
            fn(Request $inner): Response => $csrfMiddleware->process($inner, $handler),
        );

        $request = $this->createRequest(
            Method::POST,
            '/api/data',
            headers: ['X-CSRF-Token' => $token],
        );

        $response = $pipeline($request);

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('nosniff', $response->headers->first('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->first('X-Frame-Options'));
    }

    #[Test]
    public function forbiddenResponseFromCsrfStillGetsSecurityHeaders(): void
    {
        $tokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $tokenManager->method('validate')->willReturn(false);

        $csrfMiddleware = new CsrfMiddleware($tokenManager, $this->csrfConfig);
        $headersMiddleware = new SecurityHeadersMiddleware($this->headersConfig);

        $handler = fn(Request $r): Response => Response::text('OK');

        $pipeline = fn(Request $r): Response => $headersMiddleware->process(
            $r,
            fn(Request $inner): Response => $csrfMiddleware->process($inner, $handler),
        );

        $request = $this->createRequest(Method::POST, '/submit');
        $response = $pipeline($request);

        // CSRF blocks with 403
        self::assertSame(ResponseStatus::Forbidden, $response->status);

        // But security headers are still applied
        self::assertSame('nosniff', $response->headers->first('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->first('X-Frame-Options'));
    }
}
