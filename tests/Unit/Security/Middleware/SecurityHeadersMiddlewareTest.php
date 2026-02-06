<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\CrossOriginConfig;
use Pulsar\Config\CspConfig;
use Pulsar\Config\HstsConfig;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\Middleware\SecurityHeadersMiddleware;

#[CoversClass(SecurityHeadersMiddleware::class)]
final class SecurityHeadersMiddlewareTest extends TestCase
{
    /**
     * @param array<string, mixed> $server
     * @param array<string, list<string>|string> $headers
     */
    private function createRequest(array $server = [], array $headers = []): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: $headers,
            serverParams: $server,
        );
    }

    private function createHttpsRequest(): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: 'https://example.com/',
            serverParams: ['HTTPS' => 'on'],
        );
    }

    private function createForwardedHttpsRequest(): ServerRequest
    {
        return $this->createRequest(headers: ['X-Forwarded-Proto' => 'https']);
    }

    private function textHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        return $handler;
    }

    private function jsonHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::json(['status' => 'ok']));

        return $handler;
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

        $response = $middleware->process($this->createRequest(), $this->textHandler());

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
    }

    #[Test]
    public function emptyConfigAppliesMinimumDefaults(): void
    {
        $config = new SecurityHeadersConfig(headers: []);
        $middleware = new SecurityHeadersMiddleware($config);

        $response = $middleware->process($this->createRequest(), $this->textHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('0', $response->getHeaderLine('X-XSS-Protection'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $response->getHeaderLine('Permissions-Policy'));
    }

    #[Test]
    public function preservesExistingResponseHeaders(): void
    {
        $config = new SecurityHeadersConfig(headers: [
            'X-Frame-Options' => 'DENY',
        ]);

        $middleware = new SecurityHeadersMiddleware($config);

        $response = $middleware->process($this->createRequest(), $this->jsonHandler());

        // Original Content-Type preserved
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        // Security header added
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    #[Test]
    public function userHeadersOverrideMinimumDefaults(): void
    {
        $config = new SecurityHeadersConfig(headers: [
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);

        $middleware = new SecurityHeadersMiddleware($config);

        $response = $middleware->process($this->createRequest(), $this->textHandler());

        // User override takes precedence
        self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        // Other minimums still present
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    #[Test]
    public function overridesHeaderIfAlreadySet(): void
    {
        $config = new SecurityHeadersConfig(headers: [
            'X-Frame-Options' => 'DENY',
        ]);

        $middleware = new SecurityHeadersMiddleware($config);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(
            Response::text('OK')->withHeader('X-Frame-Options', 'SAMEORIGIN'),
        );

        $response = $middleware->process($this->createRequest(), $handler);

        // Config value should override
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    #[Test]
    public function hstsEmittedOnlyForHttpsRequests(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            hsts: new HstsConfig(enabled: true, maxAge: 31536000, includeSubDomains: true),
        );

        $middleware = new SecurityHeadersMiddleware($config);

        $response = $middleware->process($this->createHttpsRequest(), $this->textHandler());

        self::assertSame(
            'max-age=31536000; includeSubDomains',
            $response->getHeaderLine('Strict-Transport-Security'),
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

        $response = $middleware->process($this->createRequest(), $this->textHandler());

        self::assertSame('', $response->getHeaderLine('Strict-Transport-Security'));
    }

    #[Test]
    public function hstsEmittedWhenXForwardedProtoIsHttps(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            hsts: new HstsConfig(enabled: true, maxAge: 63072000, includeSubDomains: true, preload: true),
        );

        $middleware = new SecurityHeadersMiddleware($config);

        $response = $middleware->process($this->createForwardedHttpsRequest(), $this->textHandler());

        self::assertSame(
            'max-age=63072000; includeSubDomains; preload',
            $response->getHeaderLine('Strict-Transport-Security'),
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

        $response = $middleware->process($this->createHttpsRequest(), $this->textHandler());

        self::assertSame('', $response->getHeaderLine('Strict-Transport-Security'));
    }

    #[Test]
    public function cspHeaderPresentInResponse(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            csp: new CspConfig(enabled: true, defaultSrc: "'self'", scriptSrc: "'self' 'unsafe-inline'"),
        );

        $middleware = new SecurityHeadersMiddleware($config);

        $response = $middleware->process($this->createRequest(), $this->textHandler());

        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertNotEmpty($csp);
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    #[Test]
    public function coepAbsentByDefault(): void
    {
        $config = new SecurityHeadersConfig(headers: []);

        $middleware = new SecurityHeadersMiddleware($config);

        $response = $middleware->process($this->createRequest(), $this->textHandler());

        self::assertSame('', $response->getHeaderLine('Cross-Origin-Embedder-Policy'));
    }

    #[Test]
    public function reportOnlyCspMode(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            csp: new CspConfig(enabled: true, reportOnly: true, defaultSrc: "'self'"),
        );

        $middleware = new SecurityHeadersMiddleware($config);

        $response = $middleware->process($this->createRequest(), $this->textHandler());

        self::assertNotEmpty($response->getHeaderLine('Content-Security-Policy-Report-Only'));
        self::assertSame('', $response->getHeaderLine('Content-Security-Policy'));
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

        $response = $middleware->process($this->createRequest(), $this->textHandler());

        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Opener-Policy'));
        self::assertSame('require-corp', $response->getHeaderLine('Cross-Origin-Embedder-Policy'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
    }
}
