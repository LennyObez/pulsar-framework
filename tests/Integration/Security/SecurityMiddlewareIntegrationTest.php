<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\CsrfConfig;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
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
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
        array $post = [],
    ): ServerRequest {
        return new ServerRequest(
            method: $method,
            uri: $path,
            headers: $headers,
            parsedBody: $post !== [] ? $post : null,
        );
    }

    #[Test]
    public function securityHeadersAppliedToEveryResponse(): void
    {
        $middleware = new SecurityHeadersMiddleware($this->headersConfig);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::json(['status' => 'ok']));

        $response = $middleware->process($this->createRequest(), $handler);

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function csrfMiddlewareAllowsGetThroughThenBlocksPost(): void
    {
        $tokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $tokenManager->method('validate')->willReturn(false);

        $middleware = new CsrfMiddleware($tokenManager, $this->csrfConfig);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        // GET passes through
        $getResponse = $middleware->process(
            $this->createRequest('GET'),
            $handler,
        );
        self::assertSame(ResponseStatus::OK->value, $getResponse->getStatusCode());

        // POST without token returns 403
        $postResponse = $middleware->process(
            $this->createRequest('POST', '/submit'),
            $handler,
        );
        self::assertSame(ResponseStatus::Forbidden->value, $postResponse->getStatusCode());
    }

    #[Test]
    public function csrfMiddlewareAcceptsValidTokenInHeader(): void
    {
        $token = bin2hex(random_bytes(32));

        $tokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $tokenManager->method('validate')
            ->willReturn(true);

        $middleware = new CsrfMiddleware($tokenManager, $this->csrfConfig);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('Created'));

        $response = $middleware->process(
            $this->createRequest(
                'POST',
                '/submit',
                headers: ['X-CSRF-Token' => $token],
            ),
            $handler,
        );

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('Created', (string) $response->getBody());
    }

    #[Test]
    public function csrfMiddlewareAcceptsValidTokenInPostField(): void
    {
        $token = bin2hex(random_bytes(32));

        $tokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $tokenManager->method('validate')
            ->willReturn(true);

        $middleware = new CsrfMiddleware($tokenManager, $this->csrfConfig);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('Created'));

        $response = $middleware->process(
            $this->createRequest(
                'POST',
                '/submit',
                post: ['_csrf_token' => $token],
            ),
            $handler,
        );

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function combinedMiddlewareStackAppliesAllHeaders(): void
    {
        $token = bin2hex(random_bytes(32));

        $tokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $tokenManager->method('validate')->willReturn(true);

        $csrfMiddleware = new CsrfMiddleware($tokenManager, $this->csrfConfig);
        $headersMiddleware = new SecurityHeadersMiddleware($this->headersConfig);

        $innerHandler = $this->createStub(RequestHandlerInterface::class);
        $innerHandler->method('handle')->willReturn(Response::json(['data' => 'value']));

        // Build pipeline: security headers wraps CSRF which wraps handler
        $csrfHandler = new class ($csrfMiddleware, $innerHandler) implements RequestHandlerInterface {
            public function __construct(
                private readonly CsrfMiddleware $csrf,
                private readonly RequestHandlerInterface $inner,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->csrf->process($request, $this->inner);
            }
        };

        $request = $this->createRequest(
            'POST',
            '/api/data',
            headers: ['X-CSRF-Token' => $token],
        );

        $response = $headersMiddleware->process($request, $csrfHandler);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    #[Test]
    public function forbiddenResponseFromCsrfStillGetsSecurityHeaders(): void
    {
        $tokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $tokenManager->method('validate')->willReturn(false);

        $csrfMiddleware = new CsrfMiddleware($tokenManager, $this->csrfConfig);
        $headersMiddleware = new SecurityHeadersMiddleware($this->headersConfig);

        $innerHandler = $this->createStub(RequestHandlerInterface::class);
        $innerHandler->method('handle')->willReturn(Response::text('OK'));

        $csrfHandler = new class ($csrfMiddleware, $innerHandler) implements RequestHandlerInterface {
            public function __construct(
                private readonly CsrfMiddleware $csrf,
                private readonly RequestHandlerInterface $inner,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->csrf->process($request, $this->inner);
            }
        };

        $request = $this->createRequest('POST', '/submit');
        $response = $headersMiddleware->process($request, $csrfHandler);

        // CSRF blocks with 403
        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());

        // But security headers are still applied
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }
}
