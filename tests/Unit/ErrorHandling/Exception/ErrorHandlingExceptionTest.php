<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\ErrorHandling\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ErrorHandling\Exception\ErrorHandlingException;
use RuntimeException;

#[CoversClass(ErrorHandlingException::class)]
final class ErrorHandlingExceptionTest extends TestCase
{
    #[Test]
    public function renderFailedCreatesExceptionWithMessage(): void
    {
        $exception = ErrorHandlingException::renderFailed('Template not found');

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('Exception render failed: Template not found', $exception->getMessage());
    }

    #[Test]
    public function renderFailedWithEmptyReason(): void
    {
        $exception = ErrorHandlingException::renderFailed('');

        self::assertSame('Exception render failed: ', $exception->getMessage());
    }

    #[Test]
    public function renderFailedReturnsNewInstanceEachTime(): void
    {
        $a = ErrorHandlingException::renderFailed('reason A');
        $b = ErrorHandlingException::renderFailed('reason B');

        self::assertNotSame($a, $b);
        self::assertStringContainsString('reason A', $a->getMessage());
        self::assertStringContainsString('reason B', $b->getMessage());
    }
}
