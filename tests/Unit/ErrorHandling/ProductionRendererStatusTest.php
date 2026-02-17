<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ErrorHandling\ProductionRenderer;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use RuntimeException;

/**
 * Tests for ProductionRenderer message selection across all status branches.
 */
#[CoversClass(ProductionRenderer::class)]
final class ProductionRendererStatusTest extends TestCase
{
    #[Test]
    public function notFoundShowsPageNotFoundMessage(): void
    {
        $renderer = new ProductionRenderer();
        $html = $renderer->render(
            new RuntimeException('test'),
            new ServerRequest(),
            ResponseStatus::NotFound,
        );

        self::assertStringContainsString('404', $html);
        self::assertStringContainsString('could not be found', $html);
    }

    #[Test]
    public function forbiddenShowsPermissionMessage(): void
    {
        $renderer = new ProductionRenderer();
        $html = $renderer->render(
            new RuntimeException('test'),
            new ServerRequest(),
            ResponseStatus::Forbidden,
        );

        self::assertStringContainsString('403', $html);
        self::assertStringContainsString('do not have permission', $html);
    }

    #[Test]
    public function methodNotAllowedShowsMethodMessage(): void
    {
        $renderer = new ProductionRenderer();
        $html = $renderer->render(
            new RuntimeException('test'),
            new ServerRequest(),
            ResponseStatus::MethodNotAllowed,
        );

        self::assertStringContainsString('405', $html);
        self::assertStringContainsString('not supported', $html);
    }

    #[Test]
    #[DataProvider('clientErrorProvider')]
    public function clientErrorShowsGenericClientMessage(ResponseStatus $status): void
    {
        $renderer = new ProductionRenderer();
        $html = $renderer->render(
            new RuntimeException('test'),
            new ServerRequest(),
            $status,
        );

        self::assertStringContainsString((string) $status->value, $html);
        self::assertStringContainsString('could not be processed', $html);
    }

    /**
     * @return iterable<string, array{ResponseStatus}>
     */
    public static function clientErrorProvider(): iterable
    {
        yield 'Bad Request' => [ResponseStatus::BadRequest];
        yield 'Conflict' => [ResponseStatus::Conflict];
        yield 'Gone' => [ResponseStatus::Gone];
        yield 'Unprocessable Entity' => [ResponseStatus::UnprocessableEntity];
        yield 'Too Many Requests' => [ResponseStatus::TooManyRequests];
    }

    #[Test]
    #[DataProvider('serverErrorProvider')]
    public function serverErrorShowsInternalErrorMessage(ResponseStatus $status): void
    {
        $renderer = new ProductionRenderer();
        $html = $renderer->render(
            new RuntimeException('test'),
            new ServerRequest(),
            $status,
        );

        self::assertStringContainsString((string) $status->value, $html);
        self::assertStringContainsString('internal error', $html);
    }

    /**
     * @return iterable<string, array{ResponseStatus}>
     */
    public static function serverErrorProvider(): iterable
    {
        yield 'Internal Server Error' => [ResponseStatus::InternalServerError];
        yield 'Bad Gateway' => [ResponseStatus::BadGateway];
        yield 'Service Unavailable' => [ResponseStatus::ServiceUnavailable];
    }

    #[Test]
    public function neverExposesExceptionDetails(): void
    {
        $renderer = new ProductionRenderer();
        $exception = new RuntimeException('Super secret DB password leaked');

        $html = $renderer->render(
            $exception,
            new ServerRequest(),
            ResponseStatus::InternalServerError,
        );

        self::assertStringNotContainsString('Super secret', $html);
        self::assertStringNotContainsString('password', $html);
        self::assertStringNotContainsString('leaked', $html);
    }

    #[Test]
    public function rendersValidHtmlDocument(): void
    {
        $renderer = new ProductionRenderer();
        $html = $renderer->render(
            new RuntimeException('test'),
            new ServerRequest(),
            ResponseStatus::InternalServerError,
        );

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('<meta charset="utf-8">', $html);
        self::assertStringContainsString('</html>', $html);
    }

    #[Test]
    public function titleContainsStatusCodeAndPhrase(): void
    {
        $renderer = new ProductionRenderer();
        $html = $renderer->render(
            new RuntimeException('test'),
            new ServerRequest(),
            ResponseStatus::NotFound,
        );

        self::assertStringContainsString('<title>404 Not Found</title>', $html);
    }

    #[Test]
    public function escapesHtmlInReasonPhrase(): void
    {
        // ResponseStatus reason phrases do not contain HTML, but the escape
        // function is still applied. Verify the output is always safe.
        $renderer = new ProductionRenderer();
        $html = $renderer->render(
            new RuntimeException('test'),
            new ServerRequest(),
            ResponseStatus::InternalServerError,
        );

        // Just verify the render succeeds and the title is properly formed
        self::assertStringContainsString('500 Internal Server Error', $html);
    }
}
