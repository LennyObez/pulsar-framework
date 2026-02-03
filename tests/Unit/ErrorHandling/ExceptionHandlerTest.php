<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling;

use function is_scalar;
use function is_string;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\ErrorHandling\DevelopmentRenderer;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\ErrorHandling\HttpException;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\RoutingException;
use RuntimeException;
use Stringable;

#[CoversClass(ExceptionHandler::class)]
final class ExceptionHandlerTest extends TestCase
{
    private function createRequest(string $path = '/'): Request
    {
        return new Request(
            method: Method::GET,
            uri: $path,
            path: $path,
            queryString: '',
            headers: new HeaderBag(),
            body: '',
        );
    }

    #[Test]
    public function genericExceptionReturns500(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $response = $handler->handle(
            new RuntimeException('Unexpected error'),
            $this->createRequest(),
        );

        self::assertSame(ResponseStatus::InternalServerError, $response->status);
    }

    #[Test]
    public function routingException404ReturnsNotFound(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $response = $handler->handle(
            RoutingException::notFound('/missing'),
            $this->createRequest('/missing'),
        );

        self::assertSame(ResponseStatus::NotFound, $response->status);
    }

    #[Test]
    public function routingException405ReturnsMethodNotAllowedWithAllowHeader(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $response = $handler->handle(
            RoutingException::methodNotAllowed('/test', Method::POST, [Method::GET, Method::PUT]),
            $this->createRequest('/test'),
        );

        self::assertSame(ResponseStatus::MethodNotAllowed, $response->status);
        self::assertSame('GET, PUT', $response->headers->first('Allow'));
    }

    #[Test]
    public function httpExceptionStatusUsed(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $response = $handler->handle(
            HttpException::forbidden('No access'),
            $this->createRequest(),
        );

        self::assertSame(ResponseStatus::Forbidden, $response->status);
    }

    #[Test]
    public function httpExceptionHeadersIncluded(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $exception = new HttpException(
            ResponseStatus::TooManyRequests,
            'Rate limited',
            ['Retry-After' => '60'],
        );

        $response = $handler->handle($exception, $this->createRequest());

        self::assertSame('60', $response->headers->first('Retry-After'));
    }

    #[Test]
    public function logsExceptionWithContext(): void
    {
        $logger = new TestLogger();
        $handler = new ExceptionHandler(new DevelopmentRenderer(), $logger);

        $handler->handle(
            new RuntimeException('Server error'),
            $this->createRequest('/api/data'),
        );

        self::assertCount(1, $logger->logs);
        self::assertSame('error', $logger->logs[0]['level']);
        self::assertSame('Server error', $logger->logs[0]['message']);
        self::assertSame(500, $logger->logs[0]['context']['status']);
        self::assertSame('/api/data', $logger->logs[0]['context']['uri']);
    }

    #[Test]
    public function logs404AsWarning(): void
    {
        $logger = new TestLogger();
        $handler = new ExceptionHandler(new DevelopmentRenderer(), $logger);

        $handler->handle(
            RoutingException::notFound('/missing'),
            $this->createRequest('/missing'),
        );

        self::assertSame('warning', $logger->logs[0]['level']);
    }

    #[Test]
    public function worksWithoutLogger(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $response = $handler->handle(
            new RuntimeException('No logger'),
            $this->createRequest(),
        );

        // Should not throw, should produce a response
        self::assertSame(ResponseStatus::InternalServerError, $response->status);
    }
}

/**
 * @internal Test helper: collects log calls.
 */
final class TestLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $logs = [];

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        /** @var array<string, mixed> $context */
        $this->logs[] = [
            'level' => is_string($level) ? $level : (is_scalar($level) ? (string) $level : 'unknown'),
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
