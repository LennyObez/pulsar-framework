<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ErrorHandling\DevelopmentRenderer;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\ErrorHandling\HttpException;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use RuntimeException;

/**
 * Tests for ExceptionHandler JSON content negotiation paths.
 */
#[CoversClass(ExceptionHandler::class)]
final class ExceptionHandlerJsonTest extends TestCase
{
    #[Test]
    public function jsonAcceptHeaderReturnsJsonResponse(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/resource',
            headers: ['Accept' => 'application/json'],
        );

        $response = $handler->handle(new RuntimeException('Something broke'), $request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(500, $response->getStatusCode());
        self::assertIsArray($body);
        self::assertSame('Internal Server Error', $body['error']);
        self::assertSame(500, $body['status']);
    }

    #[Test]
    public function jsonResponseReflectsHttpExceptionStatus(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/missing',
            headers: ['Accept' => 'application/json'],
        );

        $response = $handler->handle(HttpException::notFound('Gone'), $request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $body['error']);
        self::assertSame(404, $body['status']);
    }

    #[Test]
    public function jsonResponseIncludesHttpExceptionHeaders(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $exception = new HttpException(
            ResponseStatus::TooManyRequests,
            'Rate limited',
            ['Retry-After' => '120'],
        );

        $request = new ServerRequest(
            method: 'POST',
            uri: '/api/action',
            headers: ['Accept' => 'application/json'],
        );

        $response = $handler->handle($exception, $request);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('120', $response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function jsonResponseWithoutCorrelationIdOmitsIt(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/data',
            headers: ['Accept' => 'application/json'],
        );

        $response = $handler->handle(new RuntimeException('fail'), $request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertIsArray($body);
        self::assertArrayNotHasKey('correlation_id', $body);
    }

    #[Test]
    public function htmlAcceptHeaderReturnsHtmlResponse(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $request = new ServerRequest(
            method: 'GET',
            uri: '/page',
            headers: ['Accept' => 'text/html'],
        );

        $response = $handler->handle(new RuntimeException('Broken'), $request);
        $body = (string) $response->getBody();

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('<!DOCTYPE html>', $body);
        self::assertStringContainsString('Broken', $body);
    }

    #[Test]
    public function noAcceptHeaderReturnsHtmlResponse(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $request = new ServerRequest(method: 'GET', uri: '/page');
        $response = $handler->handle(new RuntimeException('Error'), $request);
        $body = (string) $response->getBody();

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('<!DOCTYPE html>', $body);
    }

    #[Test]
    public function jsonResponseFor403Forbidden(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/admin',
            headers: ['Accept' => 'application/json'],
        );

        $response = $handler->handle(HttpException::forbidden(), $request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Forbidden', $body['error']);
        self::assertSame(403, $body['status']);
    }

    #[Test]
    public function jsonResponseFor503ServiceUnavailable(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/health',
            headers: ['Accept' => 'application/json'],
        );

        $response = $handler->handle(HttpException::serviceUnavailable(), $request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('Service Unavailable', $body['error']);
        self::assertSame(503, $body['status']);
    }
}
