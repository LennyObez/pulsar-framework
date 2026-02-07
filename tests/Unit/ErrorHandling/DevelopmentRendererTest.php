<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ErrorHandling\DevelopmentRenderer;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use RuntimeException;

#[CoversClass(DevelopmentRenderer::class)]
final class DevelopmentRendererTest extends TestCase
{
    private function createRequest(string $path = '/'): Request
    {
        return new Request(
            method: Method::GET,
            uri: $path,
            path: $path,
            queryString: 'foo=bar',
            headers: new HeaderBag(['X-Test' => 'value']),
            body: '',
            query: ['foo' => 'bar'],
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

        $request = new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag([
                'Authorization' => 'Bearer secret-token-123',
                'Content-Type' => 'application/json',
                'Cookie' => 'session=abc123',
            ]),
            body: '',
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
}
