<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\ErrorHandling\Exception\ErrorHandlingException;
use Pulsar\ErrorHandling\ExceptionRendererInterface;
use Pulsar\ErrorHandling\HttpException;
use Pulsar\ErrorHandling\HttpExceptionInterface;

#[CoversClass(Api::class)]
final class ErrorHandlingApiTest extends TestCase
{
    use ApiAssertionsTrait;

    #[Test]
    public function httpExceptionInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(HttpExceptionInterface::class);
    }

    #[Test]
    public function exceptionRendererInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(ExceptionRendererInterface::class);
    }

    #[Test]
    public function httpExceptionIsPublicApi(): void
    {
        self::assertHasApiAttribute(HttpException::class);
    }

    #[Test]
    public function errorHandlingExceptionIsPublicApi(): void
    {
        self::assertHasApiAttribute(ErrorHandlingException::class);
    }
}
