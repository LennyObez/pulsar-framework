<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\ErrorTracking\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\ErrorTracking\Exception\ErrorTrackingException;
use RuntimeException;

#[CoversClass(ErrorTrackingException::class)]
final class ErrorTrackingExceptionTest extends TestCase
{
    #[Test]
    public function maxGroupsExceededIncludesLimit(): void
    {
        $exception = ErrorTrackingException::maxGroupsExceeded(500);

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertStringContainsString('500', $exception->getMessage());
        self::assertStringContainsString('exceeded', $exception->getMessage());
    }

    #[Test]
    public function maxGroupsExceededWithZero(): void
    {
        $exception = ErrorTrackingException::maxGroupsExceeded(0);

        self::assertStringContainsString('0', $exception->getMessage());
    }

    #[Test]
    public function factoryReturnsNewInstance(): void
    {
        $a = ErrorTrackingException::maxGroupsExceeded(100);
        $b = ErrorTrackingException::maxGroupsExceeded(200);

        self::assertNotSame($a, $b);
    }
}
