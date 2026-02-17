<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\ErrorHandling\ExceptionRendererInterface;
use Pulsar\ErrorHandling\HttpException;
use Pulsar\ErrorHandling\RenderableInterface;
use Pulsar\ErrorHandling\ReportableInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Message\Stream;
use Pulsar\Http\Message\Uri;
use Pulsar\Http\ResponseStatus;
use RuntimeException;

final class RenderableReportableTest extends TestCase
{
    #[Test]
    public function renderableExceptionRendersItsOwnResponse(): void
    {
        $exception = new class extends RuntimeException implements RenderableInterface {
            public function render(ServerRequestInterface $request): ResponseInterface
            {
                return Response::json(['custom' => 'response'], 418);
            }
        };

        $handler = new ExceptionHandler(
            renderer: $this->createStub(ExceptionRendererInterface::class),
        );

        $response = $handler->handle($exception, $this->buildRequest());

        self::assertSame(418, $response->getStatusCode());
        self::assertStringContainsString('custom', (string) $response->getBody());
    }

    #[Test]
    public function reportableExceptionCallsReport(): void
    {
        $exception = new ReportableTestException();

        $handler = new ExceptionHandler(
            renderer: $this->createStub(ExceptionRendererInterface::class),
        );

        $handler->handle($exception, $this->buildRequest());

        self::assertTrue($exception->wasReported());
    }

    #[Test]
    public function reportableReturningFalseSuppressesLogging(): void
    {
        $logCalled = false;
        $logger = $this->createStub(\Psr\Log\LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function () use (&$logCalled): void {
            $logCalled = true;
        });
        $logger->method('warning')->willReturnCallback(function () use (&$logCalled): void {
            $logCalled = true;
        });

        $exception = new SuppressingReportableTestException();

        $handler = new ExceptionHandler(
            renderer: $this->createStub(ExceptionRendererInterface::class),
            logger: $logger,
        );

        $handler->handle($exception, $this->buildRequest());

        self::assertFalse($logCalled);
    }

    #[Test]
    public function combinedRenderableAndReportable(): void
    {
        $exception = new CombinedTestException();

        $handler = new ExceptionHandler(
            renderer: $this->createStub(ExceptionRendererInterface::class),
        );

        $response = $handler->handle($exception, $this->buildRequest());

        self::assertSame(422, $response->getStatusCode());
        self::assertTrue($exception->wasReported());
    }

    #[Test]
    public function nonRenderableExceptionUsesDefaultRenderer(): void
    {
        $exception = new HttpException(ResponseStatus::NotFound, 'Not Found');

        $renderer = $this->createStub(ExceptionRendererInterface::class);
        $renderer->method('render')->willReturn('<h1>404</h1>');

        $handler = new ExceptionHandler(renderer: $renderer);

        $response = $handler->handle($exception, $this->buildRequest());

        self::assertSame(404, $response->getStatusCode());
    }

    private function buildRequest(): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: Uri::fromString('/test'),
            headers: [],
            body: Stream::create(''),
            serverParams: [],
        );
    }
}

/**
 * Test exception that tracks whether report() was called.
 *
 * @internal
 */
final class ReportableTestException extends RuntimeException implements ReportableInterface
{
    private bool $reported = false;

    public function __construct()
    {
        parent::__construct('test');
    }

    public function report(): bool
    {
        $this->reported = true;

        return true;
    }

    public function wasReported(): bool
    {
        return $this->reported;
    }
}

/**
 * Test exception that suppresses default logging.
 *
 * @internal
 */
final class SuppressingReportableTestException extends RuntimeException implements ReportableInterface
{
    public function __construct()
    {
        parent::__construct('suppressed');
    }

    public function report(): bool
    {
        return false;
    }
}

/**
 * Test exception implementing both Renderable and Reportable.
 *
 * @internal
 */
final class CombinedTestException extends RuntimeException implements RenderableInterface, ReportableInterface
{
    private bool $reported = false;

    public function __construct()
    {
        parent::__construct('combined');
    }

    public function render(ServerRequestInterface $request): ResponseInterface
    {
        return Response::json(['type' => 'custom'], 422);
    }

    public function report(): bool
    {
        $this->reported = true;

        return false;
    }

    public function wasReported(): bool
    {
        return $this->reported;
    }
}
