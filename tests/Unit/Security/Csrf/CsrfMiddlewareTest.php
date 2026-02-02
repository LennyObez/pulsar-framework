<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Csrf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CsrfConfig;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\Csrf\CsrfMiddleware;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;

#[CoversClass(CsrfMiddleware::class)]
final class CsrfMiddlewareTest extends TestCase
{
    private string $validToken;
    private CsrfConfig $config;

    /** @var CsrfTokenManagerInterface&MockObject */
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

        $this->tokenManager = $this->createMock(CsrfTokenManagerInterface::class);
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

    private function successHandler(): callable
    {
        return fn(Request $r): Response => Response::text('OK');
    }

    #[Test]
    public function safeMethodsPassThrough(): void
    {
        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        foreach ([Method::GET, Method::HEAD, Method::OPTIONS] as $method) {
            $request = $this->createRequest($method);
            $response = $middleware->process($request, $this->successHandler());

            self::assertSame(ResponseStatus::OK, $response->status, "Failed for method: {$method->value}");
        }
    }

    #[Test]
    public function postWithValidHeaderTokenPasses(): void
    {
        $this->tokenManager->method('validate')
            ->with($this->validToken)
            ->willReturn(true);

        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            Method::POST,
            '/submit',
            headers: ['X-CSRF-Token' => $this->validToken],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::OK, $response->status);
    }

    #[Test]
    public function postWithValidFormFieldTokenPasses(): void
    {
        $this->tokenManager->method('validate')
            ->with($this->validToken)
            ->willReturn(true);

        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            Method::POST,
            '/submit',
            post: ['_csrf_token' => $this->validToken],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::OK, $response->status);
    }

    #[Test]
    public function postWithoutTokenReturns403(): void
    {
        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(Method::POST, '/submit');
        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::Forbidden, $response->status);

        /** @var array{error: string, message: string} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Forbidden', $body['error']);
        self::assertStringContainsString('missing', $body['message']);
    }

    #[Test]
    public function postWithInvalidTokenReturns403(): void
    {
        $this->tokenManager->method('validate')
            ->willReturn(false);

        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            Method::POST,
            '/submit',
            headers: ['X-CSRF-Token' => 'wrong_token'],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::Forbidden, $response->status);

        /** @var array{error: string, message: string} $body */
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('invalid', $body['message']);
    }

    #[Test]
    public function putAndPatchAndDeleteRequireCsrf(): void
    {
        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        foreach ([Method::PUT, Method::PATCH, Method::DELETE] as $method) {
            $request = $this->createRequest($method, '/resource');
            $response = $middleware->process($request, $this->successHandler());

            self::assertSame(
                ResponseStatus::Forbidden,
                $response->status,
                "Expected 403 for method: {$method->value}",
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

        $request = $this->createRequest(Method::POST, '/submit');
        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::OK, $response->status);
    }

    #[Test]
    public function headerTokenTakesPrecedenceOverFormField(): void
    {
        $this->tokenManager->method('validate')
            ->with($this->validToken)
            ->willReturn(true);

        $middleware = new CsrfMiddleware($this->tokenManager, $this->config);

        $request = $this->createRequest(
            Method::POST,
            '/submit',
            headers: ['X-CSRF-Token' => $this->validToken],
            post: ['_csrf_token' => 'different_token'],
        );

        $response = $middleware->process($request, $this->successHandler());

        self::assertSame(ResponseStatus::OK, $response->status);
    }
}
