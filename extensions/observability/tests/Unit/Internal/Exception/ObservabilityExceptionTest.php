<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Internal\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Internal\Exception\ObservabilityException;
use RuntimeException;

#[CoversClass(ObservabilityException::class)]
final class ObservabilityExceptionTest extends TestCase
{
    #[Test]
    public function exportFailedContainsSignalAndReason(): void
    {
        $exception = ObservabilityException::exportFailed('traces', 'connection refused');

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertStringContainsString('traces', $exception->getMessage());
        self::assertStringContainsString('connection refused', $exception->getMessage());
    }

    #[Test]
    public function queueOverflowContainsSignalAndMaxSize(): void
    {
        $exception = ObservabilityException::queueOverflow('metrics', 2048);

        self::assertStringContainsString('metrics', $exception->getMessage());
        self::assertStringContainsString('2048', $exception->getMessage());
    }

    #[Test]
    public function invalidConfigurationContainsMessage(): void
    {
        $exception = ObservabilityException::invalidConfiguration('endpoint must be a URL');

        self::assertStringContainsString('endpoint must be a URL', $exception->getMessage());
        self::assertStringContainsString('Invalid observability configuration', $exception->getMessage());
    }
}
