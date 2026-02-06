<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E;

use NoDiscard;
use Override;
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
use Pulsar\Security\Csrf\CsrfTokenManager;
use Pulsar\Security\Middleware\SecurityHeadersMiddleware;
use Pulsar\Security\Session\SessionInterface;

use function array_key_exists;

/**
 * End-to-end tests for the security pipeline.
 *
 * Verifies that security middleware (CSRF protection, security headers)
 * works correctly when integrated into the request/response cycle.
 */
#[CoversClass(CsrfMiddleware::class)]
#[CoversClass(CsrfTokenManager::class)]
#[CoversClass(SecurityHeadersMiddleware::class)]
final class SecurityPipelineTest extends TestCase
{
    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, mixed> $parsedBody
     */
    private function createRequest(
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
        array $parsedBody = [],
    ): ServerRequest {
        return new ServerRequest(
            method: $method,
            uri: $path,
            headers: $headers,
            parsedBody: $parsedBody !== [] ? $parsedBody : null,
        );
    }

    private function createHandler(callable $fn): RequestHandlerInterface
    {
        return new class ($fn) implements RequestHandlerInterface {
            /** @param callable(ServerRequestInterface): ResponseInterface $fn */
            public function __construct(private readonly mixed $fn) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->fn)($request);
            }
        };
    }

    private function createCsrfConfig(bool $enabled = true): CsrfConfig
    {
        return new CsrfConfig(
            enabled: $enabled,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
        );
    }

    private function createInMemorySession(): InMemoryTestSession
    {
        return new InMemoryTestSession();
    }

    #[Test]
    public function securityHeadersMiddlewareAppliesConfiguredHeaders(): void
    {
        $config = new SecurityHeadersConfig(headers: [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-XSS-Protection' => '1; mode=block',
        ]);

        $middleware = new SecurityHeadersMiddleware($config);
        $request = $this->createRequest();

        $response = $middleware->process(
            $request,
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('ok')),
        );

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('1; mode=block', $response->getHeaderLine('X-XSS-Protection'));
        self::assertSame('ok', (string) $response->getBody());
    }

    #[Test]
    public function securityHeadersAreAppliedToAllResponseStatuses(): void
    {
        $config = new SecurityHeadersConfig(headers: [
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $middleware = new SecurityHeadersMiddleware($config);

        $notFoundResponse = $middleware->process(
            $this->createRequest(),
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('not found')->withStatus(ResponseStatus::NotFound->value)),
        );

        self::assertSame(ResponseStatus::NotFound->value, $notFoundResponse->getStatusCode());
        self::assertSame('nosniff', $notFoundResponse->getHeaderLine('X-Content-Type-Options'));
    }

    #[Test]
    public function csrfMiddlewareAllowsSafeMethodsWithoutToken(): void
    {
        $session = $this->createInMemorySession();
        $csrfConfig = $this->createCsrfConfig();
        $tokenManager = new CsrfTokenManager($session, $csrfConfig);
        $middleware = new CsrfMiddleware($tokenManager, $csrfConfig);

        $getResponse = $middleware->process(
            $this->createRequest('GET', '/page'),
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('allowed')),
        );

        self::assertSame(ResponseStatus::OK->value, $getResponse->getStatusCode());
        self::assertSame('allowed', (string) $getResponse->getBody());
    }

    #[Test]
    public function csrfMiddlewareBlocksPostWithoutToken(): void
    {
        $session = $this->createInMemorySession();
        $csrfConfig = $this->createCsrfConfig();
        $tokenManager = new CsrfTokenManager($session, $csrfConfig);
        $middleware = new CsrfMiddleware($tokenManager, $csrfConfig);

        $response = $middleware->process(
            $this->createRequest('POST', '/submit'),
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('should not reach')),
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('CSRF token is missing', (string) $response->getBody());
    }

    #[Test]
    public function csrfMiddlewareAllowsPostWithValidHeaderToken(): void
    {
        $session = $this->createInMemorySession();
        $csrfConfig = $this->createCsrfConfig();
        $tokenManager = new CsrfTokenManager($session, $csrfConfig);
        $middleware = new CsrfMiddleware($tokenManager, $csrfConfig);

        // Generate a valid token
        $token = $tokenManager->generate();

        $request = $this->createRequest(
            method: 'POST',
            path: '/submit',
            headers: ['X-CSRF-Token' => $token],
        );

        $response = $middleware->process(
            $request,
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('accepted')),
        );

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('accepted', (string) $response->getBody());
    }

    #[Test]
    public function csrfMiddlewareAllowsPostWithValidFormFieldToken(): void
    {
        $session = $this->createInMemorySession();
        $csrfConfig = $this->createCsrfConfig();
        $tokenManager = new CsrfTokenManager($session, $csrfConfig);
        $middleware = new CsrfMiddleware($tokenManager, $csrfConfig);

        $token = $tokenManager->generate();

        $request = $this->createRequest(
            method: 'POST',
            path: '/submit',
            parsedBody: ['_csrf_token' => $token],
        );

        $response = $middleware->process(
            $request,
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('accepted via form')),
        );

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('accepted via form', (string) $response->getBody());
    }

    #[Test]
    public function csrfMiddlewareRejectsInvalidToken(): void
    {
        $session = $this->createInMemorySession();
        $csrfConfig = $this->createCsrfConfig();
        $tokenManager = new CsrfTokenManager($session, $csrfConfig);
        $middleware = new CsrfMiddleware($tokenManager, $csrfConfig);

        // Generate a valid token but submit a different one
        $tokenManager->generate();

        $request = $this->createRequest(
            method: 'POST',
            path: '/submit',
            headers: ['X-CSRF-Token' => 'invalid-token-value'],
        );

        $response = $middleware->process(
            $request,
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('should not reach')),
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('CSRF token is invalid', (string) $response->getBody());
    }

    #[Test]
    public function csrfMiddlewareIsDisabledWhenConfigSaysDisabled(): void
    {
        $session = $this->createInMemorySession();
        $csrfConfig = $this->createCsrfConfig(enabled: false);
        $tokenManager = new CsrfTokenManager($session, $csrfConfig);
        $middleware = new CsrfMiddleware($tokenManager, $csrfConfig);

        $response = $middleware->process(
            $this->createRequest('POST', '/submit'),
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('passed through')),
        );

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('passed through', (string) $response->getBody());
    }

    #[Test]
    public function csrfTokenRotationInvalidatesPreviousToken(): void
    {
        $session = $this->createInMemorySession();
        $csrfConfig = $this->createCsrfConfig();
        $tokenManager = new CsrfTokenManager($session, $csrfConfig);
        $middleware = new CsrfMiddleware($tokenManager, $csrfConfig);

        $originalToken = $tokenManager->generate();
        $newToken = $tokenManager->rotate();

        // Original token should no longer be valid
        self::assertNotSame($originalToken, $newToken);

        $requestWithOldToken = $this->createRequest(
            method: 'POST',
            path: '/submit',
            headers: ['X-CSRF-Token' => $originalToken],
        );

        $response = $middleware->process(
            $requestWithOldToken,
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('should not reach')),
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());

        // New token should work
        $requestWithNewToken = $this->createRequest(
            method: 'POST',
            path: '/submit',
            headers: ['X-CSRF-Token' => $newToken],
        );

        $validResponse = $middleware->process(
            $requestWithNewToken,
            $this->createHandler(fn(ServerRequestInterface $req): ResponseInterface => Response::text('accepted')),
        );

        self::assertSame(ResponseStatus::OK->value, $validResponse->getStatusCode());
    }

    #[Test]
    public function securityHeadersAndCsrfMiddlewareWorkTogetherInPipeline(): void
    {
        $session = $this->createInMemorySession();
        $csrfConfig = $this->createCsrfConfig();
        $tokenManager = new CsrfTokenManager($session, $csrfConfig);
        $csrfMiddleware = new CsrfMiddleware($tokenManager, $csrfConfig);

        $headersConfig = new SecurityHeadersConfig(headers: [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ]);
        $headersMiddleware = new SecurityHeadersMiddleware($headersConfig);

        $token = $tokenManager->generate();

        $request = $this->createRequest(
            method: 'POST',
            path: '/submit',
            headers: ['X-CSRF-Token' => $token],
        );

        $innerHandler = $this->createHandler(
            fn(ServerRequestInterface $r): ResponseInterface => Response::text('pipeline complete'),
        );

        // Simulate pipeline: headers middleware wraps CSRF middleware wraps handler
        $csrfHandler = new class ($csrfMiddleware, $innerHandler) implements RequestHandlerInterface {
            public function __construct(
                private readonly CsrfMiddleware $middleware,
                private readonly RequestHandlerInterface $inner,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->inner);
            }
        };

        $response = $headersMiddleware->process($request, $csrfHandler);

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('pipeline complete', (string) $response->getBody());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }
}

/**
 * In-memory session implementation for testing without PHP session functions.
 */
class InMemoryTestSession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $data = [];
    private bool $started = true;

    public function start(): void
    {
        $this->started = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    #[NoDiscard]
    #[Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function id(): string
    {
        return 'test-session-id';
    }

    public function regenerate(bool $deleteOldSession = true): void
    {
        // No-op for testing
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->started = false;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }
}
