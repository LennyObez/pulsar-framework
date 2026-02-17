<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ErrorHandling\HttpException;
use Pulsar\ErrorHandling\HttpExceptionInterface;
use Pulsar\Http\ResponseStatus;
use RuntimeException;

/**
 * Tests for HttpException factory methods and interface compliance.
 */
#[CoversClass(HttpException::class)]
final class HttpExceptionFactoriesTest extends TestCase
{
    #[Test]
    public function implementsHttpExceptionInterface(): void
    {
        $exception = HttpException::notFound();

        self::assertInstanceOf(HttpExceptionInterface::class, $exception);
    }

    #[Test]
    public function implementsRuntimeException(): void
    {
        $exception = HttpException::badRequest();

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    #[DataProvider('factoryProvider')]
    public function factoryMethodsProduceCorrectStatus(
        string $method,
        ResponseStatus $expectedStatus,
        string $expectedMessage,
    ): void {
        /** @var HttpException $exception */
        $exception = HttpException::$method();

        self::assertSame($expectedStatus, $exception->getStatusCode());
        self::assertSame($expectedMessage, $exception->getMessage());
        self::assertSame($expectedStatus->value, $exception->getCode());
        self::assertSame([], $exception->getHeaders());
    }

    /**
     * @return iterable<string, array{string, ResponseStatus, string}>
     */
    public static function factoryProvider(): iterable
    {
        yield 'notFound' => ['notFound', ResponseStatus::NotFound, 'Not Found'];
        yield 'forbidden' => ['forbidden', ResponseStatus::Forbidden, 'Forbidden'];
        yield 'badRequest' => ['badRequest', ResponseStatus::BadRequest, 'Bad Request'];
        yield 'serviceUnavailable' => ['serviceUnavailable', ResponseStatus::ServiceUnavailable, 'Service Unavailable'];
    }

    #[Test]
    #[DataProvider('factoryWithCustomMessageProvider')]
    public function factoryMethodsAcceptCustomMessage(string $method, string $customMessage): void
    {
        /** @var HttpException $exception */
        $exception = HttpException::$method($customMessage);

        self::assertSame($customMessage, $exception->getMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function factoryWithCustomMessageProvider(): iterable
    {
        yield 'notFound with message' => ['notFound', 'User 42 not found'];
        yield 'forbidden with message' => ['forbidden', 'Admin access required'];
        yield 'badRequest with message' => ['badRequest', 'Invalid JSON payload'];
        yield 'serviceUnavailable with message' => ['serviceUnavailable', 'Database offline'];
    }

    #[Test]
    public function constructorWithAllParameters(): void
    {
        $previous = new RuntimeException('root');
        $headers = ['X-RateLimit-Remaining' => '0', 'Retry-After' => '30'];

        $exception = new HttpException(
            ResponseStatus::TooManyRequests,
            'Rate limited',
            $headers,
            $previous,
        );

        self::assertSame(ResponseStatus::TooManyRequests, $exception->getStatusCode());
        self::assertSame('Rate limited', $exception->getMessage());
        self::assertSame(429, $exception->getCode());
        self::assertSame($headers, $exception->getHeaders());
        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function constructorWithEmptyMessage(): void
    {
        $exception = new HttpException(ResponseStatus::InternalServerError);

        self::assertSame('', $exception->getMessage());
        self::assertSame(500, $exception->getCode());
    }

    #[Test]
    public function exceptionChaining(): void
    {
        $root = new RuntimeException('DB connection refused');
        $middle = new RuntimeException('Repository failed', 0, $root);
        $top = new HttpException(
            ResponseStatus::ServiceUnavailable,
            'Service down',
            [],
            $middle,
        );

        self::assertSame($middle, $top->getPrevious());
        self::assertSame($root, $top->getPrevious()?->getPrevious());
    }
}
