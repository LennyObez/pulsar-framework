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
use Pulsar\Config\NelConfig;
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

    private function createForwardedHttpsRequest(string $remoteAddr = '10.0.0.1'): ServerRequest
    {
        return $this->createRequest(
            server: ['REMOTE_ADDR' => $remoteAddr],
            headers: ['X-Forwarded-Proto' => 'https'],
        );
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
        $permissionsPolicy = $response->getHeaderLine('Permissions-Policy');
        self::assertStringContainsString('camera=()', $permissionsPolicy);
        self::assertStringContainsString('microphone=()', $permissionsPolicy);
        self::assertStringContainsString('geolocation=()', $permissionsPolicy);
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
    public function hstsEmittedWhenXForwardedProtoIsHttpsFromTrustedProxy(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            hsts: new HstsConfig(enabled: true, maxAge: 63072000, includeSubDomains: true, preload: true),
        );

        $middleware = new SecurityHeadersMiddleware($config, trustedProxies: ['10.0.0.1']);

        $response = $middleware->process($this->createForwardedHttpsRequest('10.0.0.1'), $this->textHandler());

        self::assertSame(
            'max-age=63072000; includeSubDomains; preload',
            $response->getHeaderLine('Strict-Transport-Security'),
        );
    }

    #[Test]
    public function xForwardedProtoIgnoredWithoutTrustedProxies(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            hsts: new HstsConfig(enabled: true, maxAge: 31536000),
        );

        $middleware = new SecurityHeadersMiddleware($config);

        $response = $middleware->process($this->createForwardedHttpsRequest(), $this->textHandler());

        self::assertSame('', $response->getHeaderLine('Strict-Transport-Security'));
    }

    #[Test]
    public function xForwardedProtoIgnoredFromUntrustedIp(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            hsts: new HstsConfig(enabled: true, maxAge: 31536000),
        );

        $middleware = new SecurityHeadersMiddleware($config, trustedProxies: ['10.0.0.1']);

        $response = $middleware->process($this->createForwardedHttpsRequest('192.168.1.100'), $this->textHandler());

        self::assertSame('', $response->getHeaderLine('Strict-Transport-Security'));
    }

    #[Test]
    public function trustedProxyCidrRangeMatches(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            hsts: new HstsConfig(enabled: true, maxAge: 31536000, includeSubDomains: false),
        );

        $middleware = new SecurityHeadersMiddleware($config, trustedProxies: ['10.0.0.0/24']);

        $response = $middleware->process($this->createForwardedHttpsRequest('10.0.0.42'), $this->textHandler());

        self::assertSame(
            'max-age=31536000',
            $response->getHeaderLine('Strict-Transport-Security'),
        );
    }

    #[Test]
    public function trustedProxyCidrRangeRejectsOutsideIp(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            hsts: new HstsConfig(enabled: true, maxAge: 31536000, includeSubDomains: false),
        );

        $middleware = new SecurityHeadersMiddleware($config, trustedProxies: ['10.0.0.0/24']);

        $response = $middleware->process($this->createForwardedHttpsRequest('10.0.1.1'), $this->textHandler());

        self::assertSame('', $response->getHeaderLine('Strict-Transport-Security'));
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

    #[Test]
    public function xPermittedCrossDomainPoliciesIncludedByDefault(): void
    {
        $config = new SecurityHeadersConfig(headers: []);
        $middleware = new SecurityHeadersMiddleware($config);

        $response = $middleware->process($this->createRequest(), $this->textHandler());

        self::assertSame('none', $response->getHeaderLine('X-Permitted-Cross-Domain-Policies'));
    }

    #[Test]
    public function clearSiteDataNotEmittedWithoutAttribute(): void
    {
        $config = new SecurityHeadersConfig(headers: []);
        $middleware = new SecurityHeadersMiddleware($config);

        $response = $middleware->process($this->createRequest(), $this->textHandler());

        self::assertSame('', $response->getHeaderLine('Clear-Site-Data'));
    }

    #[Test]
    public function clearSiteDataEmittedWhenAttributeIsTrue(): void
    {
        $config = new SecurityHeadersConfig(headers: []);
        $middleware = new SecurityHeadersMiddleware($config);

        $request = $this->createRequest()->withAttribute(
            SecurityHeadersMiddleware::CLEAR_SITE_DATA_ATTR,
            true,
        );

        $response = $middleware->process($request, $this->textHandler());

        self::assertSame('"cache", "cookies", "storage"', $response->getHeaderLine('Clear-Site-Data'));
    }

    #[Test]
    public function clearSiteDataNotEmittedWhenAttributeIsFalse(): void
    {
        $config = new SecurityHeadersConfig(headers: []);
        $middleware = new SecurityHeadersMiddleware($config);

        $request = $this->createRequest()->withAttribute(
            SecurityHeadersMiddleware::CLEAR_SITE_DATA_ATTR,
            false,
        );

        $response = $middleware->process($request, $this->textHandler());

        self::assertSame('', $response->getHeaderLine('Clear-Site-Data'));
    }

    #[Test]
    public function nelHeadersEmittedWhenEnabled(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            nel: new NelConfig(enabled: true, reportTo: 'nel-group', maxAge: 3600),
            nelEndpointUrl: 'https://example.com/nel',
        );

        $middleware = new SecurityHeadersMiddleware($config);

        $response = $middleware->process($this->createRequest(), $this->textHandler());

        $nel = $response->getHeaderLine('NEL');
        self::assertNotEmpty($nel);
        self::assertStringContainsString('nel-group', $nel);

        $reportTo = $response->getHeaderLine('Report-To');
        self::assertNotEmpty($reportTo);
        self::assertStringContainsString('https://example.com/nel', $reportTo);
    }

    #[Test]
    public function nelHeadersAbsentWhenDisabled(): void
    {
        $config = new SecurityHeadersConfig(
            headers: [],
            nel: new NelConfig(enabled: false),
        );

        $middleware = new SecurityHeadersMiddleware($config);

        $response = $middleware->process($this->createRequest(), $this->textHandler());

        self::assertSame('', $response->getHeaderLine('NEL'));
        self::assertSame('', $response->getHeaderLine('Report-To'));
    }
}
