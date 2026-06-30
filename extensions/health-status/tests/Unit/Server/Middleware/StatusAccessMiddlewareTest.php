<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Tests\Unit\Server\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\HealthStatus\Config\HealthStatusConfig;
use Pulsar\Extension\HealthStatus\Server\Middleware\StatusAccessMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;

#[CoversClass(StatusAccessMiddleware::class)]
final class StatusAccessMiddlewareTest extends TestCase
{
    private const string VALID_TOKEN = 'test-token-123';

    #[Test]
    public function authenticatedRequestPassesThroughWhenAuthRequired(): void
    {
        $config = new HealthStatusConfig(requireAuth: true, publicSummary: false, authToken: self::VALID_TOKEN);
        $middleware = new StatusAccessMiddleware($config);

        $request = $this->makeRequestWithAuthorization('Bearer ' . self::VALID_TOKEN);
        $expectedResponse = Response::html('<p>OK</p>');
        $handler = $this->makeHandler($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Regression for the no-op auth gate: previously isAuthenticated() only
     * checked the Authorization header was non-empty, so ANY value passed.
     * A wrong token, and a non-Bearer header, must now both be rejected.
     */
    #[Test]
    public function wrongBearerTokenIsRejectedWhenAuthRequired(): void
    {
        $config = new HealthStatusConfig(requireAuth: true, publicSummary: false, authToken: self::VALID_TOKEN);
        $middleware = new StatusAccessMiddleware($config);

        $request = $this->makeRequestWithAuthorization('Bearer not-the-real-token');
        $handler = $this->makeHandler(Response::html('should not reach'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
    }

    #[Test]
    public function arbitraryNonBearerAuthorizationHeaderIsRejected(): void
    {
        $config = new HealthStatusConfig(requireAuth: true, publicSummary: false, authToken: self::VALID_TOKEN);
        $middleware = new StatusAccessMiddleware($config);

        // The old gate accepted this outright; the scheme must now be Bearer.
        $request = $this->makeRequestWithAuthorization('literally-anything-non-empty');
        $handler = $this->makeHandler(Response::html('should not reach'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
    }

    #[Test]
    public function basicSchemeIsRejectedEvenWithMatchingCredentialBytes(): void
    {
        $config = new HealthStatusConfig(requireAuth: true, publicSummary: false, authToken: self::VALID_TOKEN);
        $middleware = new StatusAccessMiddleware($config);

        $request = $this->makeRequestWithAuthorization('Basic ' . self::VALID_TOKEN);
        $handler = $this->makeHandler(Response::html('should not reach'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
    }

    /**
     * Fail-closed: requireAuth on but no credential configured must deny
     * every request rather than admit traffic against a missing secret.
     */
    #[Test]
    public function requireAuthWithNoConfiguredTokenFailsClosed(): void
    {
        $config = new HealthStatusConfig(requireAuth: true, publicSummary: false, authToken: null);
        $middleware = new StatusAccessMiddleware($config);

        $request = $this->makeRequestWithAuthorization('Bearer anything-at-all');
        $handler = $this->makeHandler(Response::html('should not reach'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
    }

    #[Test]
    public function unauthenticatedRequestReturns401WhenAuthRequired(): void
    {
        $config = new HealthStatusConfig(requireAuth: true, publicSummary: false);
        $middleware = new StatusAccessMiddleware($config);

        $request = $this->makeUnauthenticatedRequest();
        $handler = $this->makeHandler(Response::html('should not reach'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
        self::assertStringContainsString('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    }

    #[Test]
    public function unauthenticatedRequestAllowedWhenPublicSummaryEnabled(): void
    {
        $config = new HealthStatusConfig(requireAuth: true, publicSummary: true);
        $middleware = new StatusAccessMiddleware($config);

        $request = $this->makeUnauthenticatedRequest();
        $expectedResponse = Response::html('<p>Summary</p>');
        $handler = $this->makeHandler($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function noAuthRequiredPassesThrough(): void
    {
        $config = new HealthStatusConfig(requireAuth: false, publicSummary: false);
        $middleware = new StatusAccessMiddleware($config);

        $request = $this->makeUnauthenticatedRequest();
        $expectedResponse = Response::html('<p>Public</p>');
        $handler = $this->makeHandler($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function securityHeadersArePresent(): void
    {
        $config = new HealthStatusConfig(requireAuth: false);
        $middleware = new StatusAccessMiddleware($config);

        $request = $this->makeUnauthenticatedRequest();
        $handler = $this->makeHandler(Response::html('OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    #[Test]
    public function securityHeadersPresentOn401Response(): void
    {
        $config = new HealthStatusConfig(requireAuth: true, publicSummary: false);
        $middleware = new StatusAccessMiddleware($config);

        $request = $this->makeUnauthenticatedRequest();
        $handler = $this->makeHandler(Response::html('should not reach'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
        self::assertSame("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    #[Test]
    public function rateLimitReturns429WhenExceeded(): void
    {
        $config = new HealthStatusConfig(requireAuth: false, rateLimitPerMinute: 3);
        $middleware = new StatusAccessMiddleware($config);

        $request = $this->makeUnauthenticatedRequest();
        $handler = $this->makeHandler(Response::html('OK'));

        // Exhaust rate limit
        $middleware->process($request, $handler);
        $middleware->process($request, $handler);
        $middleware->process($request, $handler);

        // Fourth request should be rate-limited
        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::TooManyRequests->value, $response->getStatusCode());
        self::assertNotEmpty($response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function rateLimitIncludesRetryAfterHeader(): void
    {
        $config = new HealthStatusConfig(requireAuth: false, rateLimitPerMinute: 1);
        $middleware = new StatusAccessMiddleware($config);

        $request = $this->makeUnauthenticatedRequest();
        $handler = $this->makeHandler(Response::html('OK'));

        $middleware->process($request, $handler);
        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::TooManyRequests->value, $response->getStatusCode());

        $retryAfter = (int) $response->getHeaderLine('Retry-After');
        self::assertGreaterThanOrEqual(1, $retryAfter);
        self::assertLessThanOrEqual(60, $retryAfter);
    }

    #[Test]
    public function rateLimitResponseHasSecurityHeaders(): void
    {
        $config = new HealthStatusConfig(requireAuth: false, rateLimitPerMinute: 1);
        $middleware = new StatusAccessMiddleware($config);

        $request = $this->makeUnauthenticatedRequest();
        $handler = $this->makeHandler(Response::html('OK'));

        $middleware->process($request, $handler);
        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::TooManyRequests->value, $response->getStatusCode());
        self::assertSame("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
    }

    #[Test]
    public function unauthenticatedResponseDoesNotLeakInternalDetails(): void
    {
        $config = new HealthStatusConfig(requireAuth: true, publicSummary: false);
        $middleware = new StatusAccessMiddleware($config);

        $request = $this->makeUnauthenticatedRequest();
        $handler = $this->makeHandler(Response::html('should not reach'));

        $response = $middleware->process($request, $handler);
        $body = (string) $response->getBody();

        self::assertSame('Authentication required', $body);
        self::assertStringNotContainsString('stack', $body);
        self::assertStringNotContainsString('exception', $body);
    }

    private function makeRequestWithAuthorization(string $authorization): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name): string => strtolower($name) === 'authorization' ? $authorization : '',
        );
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
        $request->method('withAttribute')->willReturn($request);

        return $request;
    }

    private function makeUnauthenticatedRequest(): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
        $request->method('withAttribute')->willReturn($request);

        return $request;
    }

    private function makeHandler(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }
}
