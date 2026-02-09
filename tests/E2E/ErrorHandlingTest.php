<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ErrorHandling\DevelopmentRenderer;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\ErrorHandling\HttpException;
use Pulsar\ErrorHandling\ProductionRenderer;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\ResponseStatus;
use RuntimeException;

/**
 * End-to-end tests for the exception handling pipeline.
 *
 * Verifies that HttpException maps to the correct status code,
 * generic exceptions produce 500, and content negotiation (JSON vs HTML)
 * works correctly. Also ensures production mode never leaks stack traces.
 */
#[CoversClass(ExceptionHandler::class)]
#[CoversClass(HttpException::class)]
#[CoversClass(ProductionRenderer::class)]
#[CoversClass(DevelopmentRenderer::class)]
final class ErrorHandlingTest extends TestCase
{
    private function createRequest(
        Method $method = Method::GET,
        string $path = '/',
        HeaderBag $headers = new HeaderBag(),
    ): Request {
        return new Request(
            method: $method,
            uri: $path,
            path: $path,
            queryString: '',
            headers: $headers,
            body: '',
        );
    }

    // ---- HttpException -> correct status code ----

    #[Test]
    public function httpExceptionNotFoundReturns404(): void
    {
        $handler = new ExceptionHandler(new ProductionRenderer());
        $request = $this->createRequest(path: '/missing');

        $response = $handler->handle(HttpException::notFound(), $request);

        self::assertSame(ResponseStatus::NotFound, $response->status);
    }

    #[Test]
    public function httpExceptionForbiddenReturns403(): void
    {
        $handler = new ExceptionHandler(new ProductionRenderer());
        $request = $this->createRequest(path: '/secret');

        $response = $handler->handle(HttpException::forbidden(), $request);

        self::assertSame(ResponseStatus::Forbidden, $response->status);
    }

    #[Test]
    public function httpExceptionBadRequestReturns400(): void
    {
        $handler = new ExceptionHandler(new ProductionRenderer());
        $request = $this->createRequest(path: '/api/submit');

        $response = $handler->handle(HttpException::badRequest('Invalid input'), $request);

        self::assertSame(ResponseStatus::BadRequest, $response->status);
    }

    #[Test]
    public function httpExceptionServiceUnavailableReturns503(): void
    {
        $handler = new ExceptionHandler(new ProductionRenderer());
        $request = $this->createRequest(path: '/health');

        $response = $handler->handle(HttpException::serviceUnavailable(), $request);

        self::assertSame(ResponseStatus::ServiceUnavailable, $response->status);
    }

    #[Test]
    public function httpExceptionPreservesCustomHeaders(): void
    {
        $handler = new ExceptionHandler(new ProductionRenderer());
        $request = $this->createRequest();

        $exception = new HttpException(
            ResponseStatus::Unauthorized,
            'Token expired',
            ['WWW-Authenticate' => 'Bearer realm="api"'],
        );

        $response = $handler->handle($exception, $request);

        self::assertSame(ResponseStatus::Unauthorized, $response->status);
        self::assertSame('Bearer realm="api"', $response->headers->first('WWW-Authenticate'));
    }

    // ---- RuntimeException -> 500 with safe body ----

    #[Test]
    public function runtimeExceptionReturns500(): void
    {
        $handler = new ExceptionHandler(new ProductionRenderer());
        $request = $this->createRequest(path: '/api/data');

        $response = $handler->handle(
            new RuntimeException('Database connection failed'),
            $request,
        );

        self::assertSame(ResponseStatus::InternalServerError, $response->status);
    }

    #[Test]
    public function runtimeExceptionInProductionDoesNotExposeDetails(): void
    {
        $handler = new ExceptionHandler(new ProductionRenderer());
        $request = $this->createRequest(path: '/api/data');

        $response = $handler->handle(
            new RuntimeException('Database connection failed at /var/app/src/Db.php:42'),
            $request,
        );

        self::assertSame(ResponseStatus::InternalServerError, $response->status);
        // Production body should NOT contain the exception message, file paths, or stack trace
        self::assertStringNotContainsString('Database connection failed', $response->body);
        self::assertStringNotContainsString('/var/app/src/Db.php', $response->body);
        self::assertStringNotContainsString('RuntimeException', $response->body);
        // Should contain a generic error message
        self::assertStringContainsString('An internal error occurred', $response->body);
    }

    // ---- JSON Accept header -> JSON error response ----

    #[Test]
    public function jsonAcceptHeaderProducesJsonErrorResponse(): void
    {
        $handler = new ExceptionHandler(new ProductionRenderer());
        $request = $this->createRequest(
            path: '/api/resource',
            headers: new HeaderBag(['Accept' => 'application/json']),
        );

        $response = $handler->handle(
            HttpException::notFound('Resource not found'),
            $request,
        );

        self::assertSame(ResponseStatus::NotFound, $response->status);
        self::assertSame(
            'application/json; charset=utf-8',
            $response->headers->first('Content-Type'),
        );

        $data = json_decode($response->body, true);
        self::assertIsArray($data);
        self::assertSame(404, $data['status']);
        self::assertSame('Not Found', $data['error']);
    }

    #[Test]
    public function jsonAcceptHeaderWith500ProducesJsonErrorResponse(): void
    {
        $handler = new ExceptionHandler(new ProductionRenderer());
        $request = $this->createRequest(
            path: '/api/action',
            headers: new HeaderBag(['Accept' => 'application/json']),
        );

        $response = $handler->handle(
            new RuntimeException('Unexpected failure'),
            $request,
        );

        self::assertSame(ResponseStatus::InternalServerError, $response->status);
        self::assertSame(
            'application/json; charset=utf-8',
            $response->headers->first('Content-Type'),
        );

        $data = json_decode($response->body, true);
        self::assertIsArray($data);
        self::assertSame(500, $data['status']);
        self::assertSame('Internal Server Error', $data['error']);
        // JSON response should NOT contain the raw exception message
        self::assertArrayNotHasKey('message', $data);
    }

    // ---- HTML Accept header -> HTML error (no stack trace in production) ----

    #[Test]
    public function htmlAcceptHeaderProducesHtmlErrorResponse(): void
    {
        $handler = new ExceptionHandler(new ProductionRenderer());
        $request = $this->createRequest(
            path: '/page',
            headers: new HeaderBag(['Accept' => 'text/html']),
        );

        $response = $handler->handle(
            HttpException::notFound(),
            $request,
        );

        self::assertSame(ResponseStatus::NotFound, $response->status);
        self::assertSame(
            'text/html; charset=utf-8',
            $response->headers->first('Content-Type'),
        );
        self::assertStringContainsString('404', $response->body);
    }

    #[Test]
    public function productionRendererDoesNotExposeStackTrace(): void
    {
        $handler = new ExceptionHandler(new ProductionRenderer());
        $request = $this->createRequest(path: '/crash');

        $exception = new RuntimeException('Secret error details with path /etc/secrets/key.pem');

        $response = $handler->handle($exception, $request);

        self::assertSame(ResponseStatus::InternalServerError, $response->status);
        // Production should never show stack trace or sensitive paths
        self::assertStringNotContainsString('Stack Trace', $response->body);
        self::assertStringNotContainsString('#0', $response->body);
        self::assertStringNotContainsString('/etc/secrets/key.pem', $response->body);
        self::assertStringNotContainsString('Secret error details', $response->body);
    }

    #[Test]
    public function developmentRendererIncludesStackTraceAndDetails(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());
        $request = $this->createRequest(path: '/debug');

        $exception = new RuntimeException('Debug error message');

        $response = $handler->handle($exception, $request);

        self::assertSame(ResponseStatus::InternalServerError, $response->status);
        // Development renderer should include class name, message, and stack trace
        self::assertStringContainsString('RuntimeException', $response->body);
        self::assertStringContainsString('Debug error message', $response->body);
        self::assertStringContainsString('Stack Trace', $response->body);
    }

    #[Test]
    public function developmentRendererJsonResponseStillHidesMessageInBody(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());
        $request = $this->createRequest(
            path: '/api/debug',
            headers: new HeaderBag(['Accept' => 'application/json']),
        );

        $response = $handler->handle(
            new RuntimeException('Should not leak in JSON'),
            $request,
        );

        self::assertSame(ResponseStatus::InternalServerError, $response->status);

        $data = json_decode($response->body, true);
        self::assertIsArray($data);
        // JSON error response always uses generic error name, not raw exception message
        self::assertSame('Internal Server Error', $data['error']);
    }
}
