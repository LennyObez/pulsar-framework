<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E;

use NoDiscard;
use Override;
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
     * @param array<string, mixed> $post
     */
    private function createRequest(
        Method $method = Method::GET,
        string $path = '/',
        HeaderBag $headers = new HeaderBag(),
        array $post = [],
    ): Request {
        return new Request(
            method: $method,
            uri: $path,
            path: $path,
            queryString: '',
            headers: $headers,
            body: '',
            post: $post,
        );
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
            fn(Request $req): Response => Response::text('ok'),
        );

        self::assertSame('nosniff', $response->headers->first('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->first('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->first('Referrer-Policy'));
        self::assertSame('1; mode=block', $response->headers->first('X-XSS-Protection'));
        self::assertSame('ok', $response->body);
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
            fn(Request $req): Response => Response::text('not found')->withStatus(ResponseStatus::NotFound),
        );

        self::assertSame(ResponseStatus::NotFound, $notFoundResponse->status);
        self::assertSame('nosniff', $notFoundResponse->headers->first('X-Content-Type-Options'));
    }

    #[Test]
    public function csrfMiddlewareAllowsSafeMethodsWithoutToken(): void
    {
        $session = $this->createInMemorySession();
        $csrfConfig = $this->createCsrfConfig();
        $tokenManager = new CsrfTokenManager($session, $csrfConfig);
        $middleware = new CsrfMiddleware($tokenManager, $csrfConfig);

        $getResponse = $middleware->process(
            $this->createRequest(Method::GET, '/page'),
            fn(Request $req): Response => Response::text('allowed'),
        );

        self::assertSame(ResponseStatus::OK, $getResponse->status);
        self::assertSame('allowed', $getResponse->body);
    }

    #[Test]
    public function csrfMiddlewareBlocksPostWithoutToken(): void
    {
        $session = $this->createInMemorySession();
        $csrfConfig = $this->createCsrfConfig();
        $tokenManager = new CsrfTokenManager($session, $csrfConfig);
        $middleware = new CsrfMiddleware($tokenManager, $csrfConfig);

        $response = $middleware->process(
            $this->createRequest(Method::POST, '/submit'),
            fn(Request $req): Response => Response::text('should not reach'),
        );

        self::assertSame(ResponseStatus::Forbidden, $response->status);
        self::assertStringContainsString('CSRF token is missing', $response->body);
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
            method: Method::POST,
            path: '/submit',
            headers: new HeaderBag(['X-CSRF-Token' => $token]),
        );

        $response = $middleware->process(
            $request,
            fn(Request $req): Response => Response::text('accepted'),
        );

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('accepted', $response->body);
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
            method: Method::POST,
            path: '/submit',
            post: ['_csrf_token' => $token],
        );

        $response = $middleware->process(
            $request,
            fn(Request $req): Response => Response::text('accepted via form'),
        );

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('accepted via form', $response->body);
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
            method: Method::POST,
            path: '/submit',
            headers: new HeaderBag(['X-CSRF-Token' => 'invalid-token-value']),
        );

        $response = $middleware->process(
            $request,
            fn(Request $req): Response => Response::text('should not reach'),
        );

        self::assertSame(ResponseStatus::Forbidden, $response->status);
        self::assertStringContainsString('CSRF token is invalid', $response->body);
    }

    #[Test]
    public function csrfMiddlewareIsDisabledWhenConfigSaysDisabled(): void
    {
        $session = $this->createInMemorySession();
        $csrfConfig = $this->createCsrfConfig(enabled: false);
        $tokenManager = new CsrfTokenManager($session, $csrfConfig);
        $middleware = new CsrfMiddleware($tokenManager, $csrfConfig);

        $response = $middleware->process(
            $this->createRequest(Method::POST, '/submit'),
            fn(Request $req): Response => Response::text('passed through'),
        );

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('passed through', $response->body);
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
            method: Method::POST,
            path: '/submit',
            headers: new HeaderBag(['X-CSRF-Token' => $originalToken]),
        );

        $response = $middleware->process(
            $requestWithOldToken,
            fn(Request $req): Response => Response::text('should not reach'),
        );

        self::assertSame(ResponseStatus::Forbidden, $response->status);

        // New token should work
        $requestWithNewToken = $this->createRequest(
            method: Method::POST,
            path: '/submit',
            headers: new HeaderBag(['X-CSRF-Token' => $newToken]),
        );

        $validResponse = $middleware->process(
            $requestWithNewToken,
            fn(Request $req): Response => Response::text('accepted'),
        );

        self::assertSame(ResponseStatus::OK, $validResponse->status);
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
            method: Method::POST,
            path: '/submit',
            headers: new HeaderBag(['X-CSRF-Token' => $token]),
        );

        // Simulate pipeline: headers middleware wraps CSRF middleware wraps handler
        $response = $headersMiddleware->process(
            $request,
            fn(Request $req): Response => $csrfMiddleware->process(
                $req,
                fn(Request $r): Response => Response::text('pipeline complete'),
            ),
        );

        self::assertSame(ResponseStatus::OK, $response->status);
        self::assertSame('pipeline complete', $response->body);
        self::assertSame('nosniff', $response->headers->first('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->first('X-Frame-Options'));
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
