<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Csrf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\CsrfConfig;
use Pulsar\ErrorHandling\ExceptionRendererInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\Csrf\CsrfMiddleware;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;
use Throwable;

#[CoversClass(CsrfMiddleware::class)]
final class CsrfMiddlewareTest extends TestCase
{
    private string $validToken;
    private CsrfConfig $config;

    /** @var CsrfTokenManagerInterface&Stub */
    private CsrfTokenManagerInterface $tokenManager;

    protected function setUp(): void
    {
        $this->validToken = bin2hex(random_bytes(32));

        $this->config = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
        );

        $this->tokenManager = $this->createStub(CsrfTokenManagerInterface::class);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $parsedBody
     */
    private function createRequest(
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
        ?array $parsedBody = null,
        string $body = '',
    ): ServerRequest {
        return new ServerRequest(
            method: $method,
            uri: $path,
            headers: $headers,
            body: $body,
            parsedBody: $parsedBody,
        );
    }

    private function successHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(Response::text('OK'));

        return $handler;
    }

    #[Test]
    public function safeMethodsPassThrough(): void
    {
        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $request = $this->createRequest($method);
            $response = $middleware->process($request, $this->successHandler());

            self::assertSame(ResponseStatus::OK->value, $response->getStatusCode(), "Failed for method: {$method}");
        }
    }

    #[Test]
    public function postWithValidHeaderTokenPasses(): void
    {
        $this->tokenManager->method('validate')
            ->willReturn(true);

        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['X-CSRF-Token' => $this->validToken],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function postWithValidFormFieldTokenPasses(): void
    {
        $this->tokenManager->method('validate')
            ->willReturn(true);

        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            'POST',
            '/submit',
            parsedBody: ['_csrf_token' => $this->validToken],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function postWithoutTokenReturns403Json(): void
    {
        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['Accept' => 'application/json'],
        );
        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());

        /** @var array{error: string, message: string} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Forbidden', $body['error']);
        self::assertStringContainsString('missing', $body['message']);
    }

    #[Test]
    public function postWithInvalidTokenReturns403Json(): void
    {
        $this->tokenManager->method('validate')
            ->willReturn(false);

        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['X-CSRF-Token' => 'wrong_token', 'Accept' => 'application/json'],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());

        /** @var array{error: string, message: string} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('invalid', $body['message']);
    }

    #[Test]
    public function putAndPatchAndDeleteRequireCsrf(): void
    {
        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $request = $this->createRequest($method, '/resource');
            $response = $middleware->process($request, $this->successHandler());

            self::assertSame(
                ResponseStatus::Forbidden->value,
                $response->getStatusCode(),
                "Expected 403 for method: {$method}",
            );
        }
    }

    #[Test]
    public function disabledCsrfSkipsValidation(): void
    {
        $config = new CsrfConfig(
            enabled: false,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
        );

        $middleware = new CsrfMiddleware($this->tokenManager, $config);

        $request = $this->createRequest('POST', '/submit');
        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function postWithoutTokenReturnsHtmlForBrowserRequest(): void
    {
        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['Accept' => 'text/html'],
        );
        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('403 Forbidden', (string) $response->getBody());
        self::assertStringContainsString('CSRF token is missing', (string) $response->getBody());
    }

    #[Test]
    public function xhrRequestWithoutTokenReturnsJson(): void
    {
        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['X-Requested-With' => 'XMLHttpRequest'],
        );
        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());

        /** @var array{error: string, message: string} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Forbidden', $body['error']);
    }

    #[Test]
    public function headerTokenTakesPrecedenceOverFormField(): void
    {
        $this->tokenManager->method('validate')
            ->willReturn(true);

        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['X-CSRF-Token' => $this->validToken],
            parsedBody: ['_csrf_token' => 'different_token'],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function crossOriginRequestRejected(): void
    {
        $config = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
            trustedOrigins: ['https://example.com'],
            originValidation: 'optional',
        );

        $middleware = new CsrfMiddleware($this->tokenManager, $config);

        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['Origin' => 'https://evil.com', 'X-CSRF-Token' => $this->validToken],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function sameOriginRequestPasses(): void
    {
        $this->tokenManager->method('validate')->willReturn(true);

        $config = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
            trustedOrigins: ['https://example.com'],
            originValidation: 'optional',
        );

        $middleware = new CsrfMiddleware($this->tokenManager, $config);

        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['Origin' => 'https://example.com', 'X-CSRF-Token' => $this->validToken],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function portNormalizationMatchesDefaultPorts(): void
    {
        $this->tokenManager->method('validate')->willReturn(true);

        $config = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
            trustedOrigins: ['https://example.com'],
            originValidation: 'optional',
        );

        $middleware = new CsrfMiddleware($this->tokenManager, $config);

        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['Origin' => 'https://example.com:443', 'X-CSRF-Token' => $this->validToken],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function requiredModeRejectsMissingOriginHeader(): void
    {
        $config = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
            trustedOrigins: ['https://example.com'],
            originValidation: 'required',
        );

        $middleware = new CsrfMiddleware($this->tokenManager, $config);

        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['X-CSRF-Token' => $this->validToken],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function refererFallbackWhenOriginAbsent(): void
    {
        $this->tokenManager->method('validate')->willReturn(true);

        $config = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
            trustedOrigins: ['https://example.com'],
            originValidation: 'optional',
        );

        $middleware = new CsrfMiddleware($this->tokenManager, $config);

        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['Referer' => 'https://example.com/page?q=1', 'X-CSRF-Token' => $this->validToken],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function originNullStringIsRejectedAsCrossOrigin(): void
    {
        $this->tokenManager->method('validate')->willReturn(true);

        $config = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
            trustedOrigins: ['https://example.com'],
            originValidation: 'optional',
        );

        $middleware = new CsrfMiddleware($this->tokenManager, $config);

        // "Origin: null" is emitted by sandboxed iframes, data: navigations and
        // some redirect laundering — a legitimate first-party request never
        // sends it, so it is a cross-origin signal, NOT an absent one. Rejecting
        // it costs non-browser clients nothing (they omit the header).
        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['Origin' => 'null', 'X-CSRF-Token' => $this->validToken],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function crossOriginRejectedWithZeroConfigViaSameOriginDerivation(): void
    {
        // No trusted_origins configured. The expected origin is derived from the
        // request's own host, so a cross-origin POST is rejected out of the box.
        $this->tokenManager->method('validate')->willReturn(true);

        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            'POST',
            'https://app.example.com/submit',
            headers: ['Origin' => 'https://evil.example.net', 'X-CSRF-Token' => $this->validToken],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function sameOriginAcceptedWithZeroConfig(): void
    {
        $this->tokenManager->method('validate')->willReturn(true);

        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        // Origin equals the request's own scheme+host (default https port elided).
        $request = $this->createRequest(
            'POST',
            'https://app.example.com/submit',
            headers: ['Origin' => 'https://app.example.com', 'X-CSRF-Token' => $this->validToken],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function secFetchSiteCrossSiteRejectedEvenWithoutOrigin(): void
    {
        // Origin suppressed, but the browser's Sec-Fetch-Site proves cross-site.
        $this->tokenManager->method('validate')->willReturn(true);

        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            'POST',
            'https://app.example.com/submit',
            headers: ['Sec-Fetch-Site' => 'cross-site', 'X-CSRF-Token' => $this->validToken],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function absentOriginSignalsFallThroughToTokenInOptionalMode(): void
    {
        // A non-browser client (no Origin, no Sec-Fetch-Site, no Referer) carries
        // no ambient cookies and so cannot mount CSRF; optional mode lets it
        // through to the token check rather than 403-ing legitimate API clients.
        $this->tokenManager->method('validate')->willReturn(true);

        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            'POST',
            'https://app.example.com/submit',
            headers: ['X-CSRF-Token' => $this->validToken],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function absentOriginSignalsAreRejectedInRequiredMode(): void
    {
        $this->tokenManager->method('validate')->willReturn(true);

        $config = new CsrfConfig(
            enabled: true,
            tokenLength: 32,
            headerName: 'X-CSRF-Token',
            formFieldName: '_csrf_token',
            originValidation: 'required',
        );

        $middleware = new CsrfMiddleware($this->tokenManager, $config);

        $request = $this->createRequest(
            'POST',
            'https://app.example.com/submit',
            headers: ['X-CSRF-Token' => $this->validToken],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    /**
     * A SPA POSTing `Content-Type: application/json` with the CSRF
     * token in the JSON body must be accepted. `getParsedBody()` only
     * covers form-encoded payloads, so the middleware also decodes a
     * JSON body; without that, JSON callers are rejected with
     * `CSRF token is missing`.
     */
    #[Test]
    public function postWithValidJsonBodyTokenPasses(): void
    {
        $this->tokenManager->method('validate')->willReturn(true);

        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['Content-Type' => 'application/json'],
            body: json_encode(['_csrf_token' => $this->validToken], JSON_THROW_ON_ERROR),
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function postWithJsonBodyMissingTokenIsRejected(): void
    {
        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['Content-Type' => 'application/json'],
            body: json_encode(['payload' => 'no-token'], JSON_THROW_ON_ERROR),
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function postWithMalformedJsonBodyIsRejected(): void
    {
        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['Content-Type' => 'application/json'],
            body: '{malformed',
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function postWithJsonContentTypeContainingCharsetParsesBody(): void
    {
        $this->tokenManager->method('validate')->willReturn(true);

        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['Content-Type' => 'application/json; charset=utf-8'],
            body: json_encode(['_csrf_token' => $this->validToken], JSON_THROW_ON_ERROR),
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::OK->value, $response->getStatusCode());
    }

    #[Test]
    public function postWithOversizedJsonBodyDoesNotParse(): void
    {
        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        // 300 KiB body — over the 256 KiB inspection cap.
        $padding = str_repeat('a', 300_000);
        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['Content-Type' => 'application/json'],
            body: json_encode(
                ['_csrf_token' => $this->validToken, 'padding' => $padding],
                JSON_THROW_ON_ERROR,
            ),
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function rendersThemedHtmlViaConfiguredRendererForBrowserRequest(): void
    {
        // A configured error-page renderer themes/localizes the 403 page just
        // like every other 4xx; the middleware must delegate to it (not emit a
        // hardcoded document) when one is wired through the composition root.
        $renderer = new class implements ExceptionRendererInterface {
            public function render(Throwable $exception, ServerRequestInterface $request, ResponseStatus $status): string
            {
                return '<main data-themed="1">' . $status->value . ': ' . $exception->getMessage() . '</main>';
            }
        };

        $middleware = new CsrfMiddleware(
            $this->tokenManager,
            $this->config,
            static fn(): ExceptionRendererInterface => $renderer,
        );

        $request = $this->createRequest(
            'POST',
            '/submit',
            headers: ['Accept' => 'text/html'],
        );
        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            '<main data-themed="1">403: CSRF token is missing</main>',
            (string) $response->getBody(),
        );
    }
}
