<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\ErrorHandling\DevelopmentRenderer;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\ErrorHandling\HttpException;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\RoutingException;
use RuntimeException;
use Stringable;
use Throwable;

use function is_scalar;
use function is_string;

#[CoversClass(ExceptionHandler::class)]
final class ExceptionHandlerTest extends TestCase
{
    private function createRequest(string $path = '/'): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: $path,
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

        self::assertSame(ResponseStatus::InternalServerError->value, $response->getStatusCode());
    }

    #[Test]
    public function routingException404ReturnsNotFound(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $response = $handler->handle(
            RoutingException::notFound('/missing'),
            $this->createRequest('/missing'),
        );

        self::assertSame(ResponseStatus::NotFound->value, $response->getStatusCode());
    }

    #[Test]
    public function routingException405ReturnsMethodNotAllowedWithAllowHeader(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $response = $handler->handle(
            RoutingException::methodNotAllowed('/test', \Pulsar\Http\Method::POST, [\Pulsar\Http\Method::GET, \Pulsar\Http\Method::PUT]),
            $this->createRequest('/test'),
        );

        self::assertSame(ResponseStatus::MethodNotAllowed->value, $response->getStatusCode());
        self::assertSame('GET, PUT', $response->getHeaderLine('Allow'));
    }

    #[Test]
    public function httpExceptionStatusUsed(): void
    {
        $handler = new ExceptionHandler(new DevelopmentRenderer());

        $response = $handler->handle(
            HttpException::forbidden('No access'),
            $this->createRequest(),
        );

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
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

        self::assertSame('60', $response->getHeaderLine('Retry-After'));
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
        self::assertSame(ResponseStatus::InternalServerError->value, $response->getStatusCode());
    }

    #[Test]
    public function logsCorrelationIdFromContextHolder(): void
    {
        $logger = new TestLogger();
        $holder = new RequestContextHolder();
        $correlationId = CorrelationId::fromString(str_repeat('ab', 16));

        $holder->set(new RequestContext(
            correlationId: $correlationId,
            causationId: CausationId::fromString(str_repeat('cd', 16)),
        ));

        $handler = new ExceptionHandler(
            new DevelopmentRenderer(),
            $logger,
            requestContextHolder: $holder,
        );

        $handler->handle(
            new RuntimeException('Correlated error'),
            $this->createRequest('/api/test'),
        );

        self::assertCount(1, $logger->logs);
        self::assertArrayHasKey('correlation_id', $logger->logs[0]['context']);
        self::assertSame(str_repeat('ab', 16), $logger->logs[0]['context']['correlation_id']);
    }

    #[Test]
    public function jsonResponseIncludesCorrelationId(): void
    {
        $holder = new RequestContextHolder();
        $correlationId = CorrelationId::fromString(str_repeat('ef', 16));

        $holder->set(new RequestContext(
            correlationId: $correlationId,
            causationId: CausationId::fromString(str_repeat('12', 16)),
        ));

        $handler = new ExceptionHandler(
            new DevelopmentRenderer(),
            requestContextHolder: $holder,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/data',
            headers: ['Accept' => 'application/json'],
        );

        $response = $handler->handle(
            new RuntimeException('JSON error'),
            $request,
        );

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('correlation_id', $body);
        self::assertSame(str_repeat('ef', 16), $body['correlation_id']);
    }

    #[Test]
    public function worksWithoutCorrelationId(): void
    {
        $logger = new TestLogger();
        $handler = new ExceptionHandler(new DevelopmentRenderer(), $logger);

        $handler->handle(
            new RuntimeException('No context'),
            $this->createRequest(),
        );

        self::assertCount(1, $logger->logs);
        self::assertArrayNotHasKey('correlation_id', $logger->logs[0]['context']);
    }

    #[Test]
    public function fallbackBodyRendersCompleteHtmlDocument(): void
    {
        // Use a renderer that always throws to trigger the fallback
        $failingRenderer = new class implements \Pulsar\ErrorHandling\ExceptionRendererInterface {
            public function render(Throwable $exception, \Psr\Http\Message\ServerRequestInterface $request, ResponseStatus $status): string
            {
                throw new RuntimeException('Renderer failed — database connection lost');
            }
        };

        $handler = new ExceptionHandler($failingRenderer);

        $response = $handler->handle(
            new RuntimeException('Database went away'),
            $this->createRequest(),
        );

        $body = (string) $response->getBody();

        // Should produce a complete HTML document, not just a fragment
        self::assertStringContainsString('<!DOCTYPE html>', $body);
        self::assertStringContainsString('<html lang="en">', $body);
        self::assertStringContainsString('<meta charset="utf-8">', $body);
        self::assertStringContainsString('<title>500 Internal Server Error</title>', $body);
        self::assertStringContainsString('Return to homepage', $body);
        self::assertSame(500, $response->getStatusCode());
    }

    #[Test]
    public function fallbackBodyFor404RendersNotFoundPage(): void
    {
        $failingRenderer = new class implements \Pulsar\ErrorHandling\ExceptionRendererInterface {
            public function render(Throwable $exception, \Psr\Http\Message\ServerRequestInterface $request, ResponseStatus $status): string
            {
                throw new RuntimeException('Template engine broken');
            }
        };

        $handler = new ExceptionHandler($failingRenderer);

        $response = $handler->handle(
            RoutingException::notFound('/missing-page'),
            $this->createRequest('/missing-page'),
        );

        $body = (string) $response->getBody();

        self::assertStringContainsString('<!DOCTYPE html>', $body);
        self::assertStringContainsString('<title>404 Not Found</title>', $body);
        self::assertSame(404, $response->getStatusCode());
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
