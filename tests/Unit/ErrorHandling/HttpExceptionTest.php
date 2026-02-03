<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ErrorHandling\HttpException;
use Pulsar\Http\ResponseStatus;
use RuntimeException;

#[CoversClass(HttpException::class)]
final class HttpExceptionTest extends TestCase
{
    #[Test]
    public function constructorSetsStatusCodeAndMessage(): void
    {
        $exception = new HttpException(ResponseStatus::NotFound, 'Page not found');

        self::assertSame(ResponseStatus::NotFound, $exception->getStatusCode());
        self::assertSame('Page not found', $exception->getMessage());
        self::assertSame(404, $exception->getCode());
    }

    #[Test]
    public function constructorSetsHeaders(): void
    {
        $headers = ['X-Retry-After' => '300'];
        $exception = new HttpException(ResponseStatus::ServiceUnavailable, 'Busy', $headers);

        self::assertSame($headers, $exception->getHeaders());
    }

    #[Test]
    public function constructorSetsPreviousException(): void
    {
        $previous = new RuntimeException('root cause');
        $exception = new HttpException(ResponseStatus::InternalServerError, 'fail', [], $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    #[Test]
    public function notFoundFactory(): void
    {
        $exception = HttpException::notFound();

        self::assertSame(ResponseStatus::NotFound, $exception->getStatusCode());
        self::assertSame('Not Found', $exception->getMessage());
    }

    #[Test]
    public function notFoundFactoryWithCustomMessage(): void
    {
        $exception = HttpException::notFound('Custom 404');

        self::assertSame('Custom 404', $exception->getMessage());
    }

    #[Test]
    public function forbiddenFactory(): void
    {
        $exception = HttpException::forbidden();

        self::assertSame(ResponseStatus::Forbidden, $exception->getStatusCode());
        self::assertSame('Forbidden', $exception->getMessage());
    }

    #[Test]
    public function badRequestFactory(): void
    {
        $exception = HttpException::badRequest();

        self::assertSame(ResponseStatus::BadRequest, $exception->getStatusCode());
        self::assertSame('Bad Request', $exception->getMessage());
    }

    #[Test]
    public function serviceUnavailableFactory(): void
    {
        $exception = HttpException::serviceUnavailable();

        self::assertSame(ResponseStatus::ServiceUnavailable, $exception->getStatusCode());
        self::assertSame('Service Unavailable', $exception->getMessage());
    }

    #[Test]
    public function getHeadersDefaultsToEmptyArray(): void
    {
        $exception = HttpException::notFound();

        self::assertSame([], $exception->getHeaders());
    }
}
