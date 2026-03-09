<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ErrorHandling\DevelopmentRenderer;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use RuntimeException;

#[CoversClass(DevelopmentRenderer::class)]
final class DevelopmentRendererTest extends TestCase
{
    private function createRequest(string $path = '/'): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: $path,
            headers: ['X-Test' => 'value'],
            queryParams: ['foo' => 'bar'],
        );
    }

    #[Test]
    public function outputContainsExceptionMessage(): void
    {
        $renderer = new DevelopmentRenderer();
        $exception = new RuntimeException('Something went wrong');

        $html = $renderer->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringContainsString('Something went wrong', $html);
    }

    #[Test]
    public function outputContainsExceptionClass(): void
    {
        $renderer = new DevelopmentRenderer();
        $exception = new RuntimeException('test');

        $html = $renderer->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringContainsString('RuntimeException', $html);
    }

    #[Test]
    public function outputContainsStackTrace(): void
    {
        $renderer = new DevelopmentRenderer();
        $exception = new RuntimeException('test');

        $html = $renderer->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        // Stack trace should contain this test file
        self::assertStringContainsString('DevelopmentRendererTest', $html);
    }

    #[Test]
    public function outputContainsRequestDetails(): void
    {
        $renderer = new DevelopmentRenderer();
        $exception = new RuntimeException('test');

        $html = $renderer->render($exception, $this->createRequest('/api/test'), ResponseStatus::InternalServerError);

        self::assertStringContainsString('GET', $html);
        self::assertStringContainsString('/api/test', $html);
    }

    #[Test]
    public function outputContainsRequestHeaders(): void
    {
        $renderer = new DevelopmentRenderer();
        $exception = new RuntimeException('test');

        $html = $renderer->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringContainsString('X-Test', $html);
    }

    #[Test]
    public function outputContainsQueryParameters(): void
    {
        $renderer = new DevelopmentRenderer();
        $exception = new RuntimeException('test');

        $html = $renderer->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringContainsString('foo', $html);
        self::assertStringContainsString('bar', $html);
    }

    #[Test]
    public function htmlEscapesValues(): void
    {
        $renderer = new DevelopmentRenderer();
        $exception = new RuntimeException('Error with <script>alert("xss")</script>');

        $html = $renderer->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function showsPreviousException(): void
    {
        $renderer = new DevelopmentRenderer();
        $previous = new RuntimeException('Root cause');
        $exception = new RuntimeException('Wrapper', 0, $previous);

        $html = $renderer->render($exception, $this->createRequest(), ResponseStatus::InternalServerError);

        self::assertStringContainsString('Root cause', $html);
        self::assertStringContainsString('Previous Exceptions', $html);
    }

    #[Test]
    public function showsStatusCode(): void
    {
        $renderer = new DevelopmentRenderer();
        $exception = new RuntimeException('not found');

        $html = $renderer->render($exception, $this->createRequest(), ResponseStatus::NotFound);

        self::assertStringContainsString('404', $html);
        self::assertStringContainsString('Not Found', $html);
    }

    #[Test]
    public function scrubsSensitiveHeaders(): void
    {
        $renderer = new DevelopmentRenderer();
        $exception = new RuntimeException('test');

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: [
                'Authorization' => 'Bearer secret-token-123',
                'Content-Type' => 'application/json',
                'Cookie' => 'session=abc123',
            ],
        );

        $html = $renderer->render($exception, $request, ResponseStatus::InternalServerError);

        // Sensitive headers should be redacted
        self::assertStringNotContainsString('secret-token-123', $html);
        self::assertStringNotContainsString('abc123', $html);
        self::assertStringContainsString('[REDACTED]', $html);

        // Normal headers should still appear
        self::assertStringContainsString('Content-Type', $html);
        self::assertStringContainsString('application/json', $html);
    }

    /**
     * F4.7: query parameters with sensitive names (`token`, `password`,
     * `api_key`) used to render verbatim in the development page,
     * leaking the values whenever APP_DEBUG=true was accidentally
     * enabled in production. The renderer now passes query params
     * through the same scrubber as headers.
     */
    #[Test]
    public function scrubsSensitiveQueryParameters(): void
    {
        $renderer = new DevelopmentRenderer();
        $exception = new RuntimeException('test');

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/resource',
            queryParams: [
                'page' => '1',
                'token' => 'super-secret-token-abc',
                'api_key' => 'sk_live_123456',
                'password' => 'hunter2',
            ],
        );

        $html = $renderer->render($exception, $request, ResponseStatus::InternalServerError);

        self::assertStringNotContainsString('super-secret-token-abc', $html);
        self::assertStringNotContainsString('sk_live_123456', $html);
        self::assertStringNotContainsString('hunter2', $html);
        self::assertStringContainsString('[REDACTED]', $html);

        // Non-sensitive params still rendered
        self::assertStringContainsString('page', $html);
        self::assertStringContainsString('1', $html);
    }
}
