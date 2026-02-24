<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OpenTelemetry\Internal\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Exception\OpenTelemetryException;
use RuntimeException;

#[CoversClass(OpenTelemetryException::class)]
final class OpenTelemetryExceptionTest extends TestCase
{
    #[Test]
    public function exportFailedIncludesSignalAndReason(): void
    {
        $e = OpenTelemetryException::exportFailed('traces', 'connection refused');

        self::assertStringContainsString('traces', $e->getMessage());
        self::assertStringContainsString('connection refused', $e->getMessage());
        self::assertInstanceOf(RuntimeException::class, $e);
    }

    #[Test]
    public function queueOverflowIncludesSignalAndMaxSize(): void
    {
        $e = OpenTelemetryException::queueOverflow('metrics', 2048);

        self::assertStringContainsString('metrics', $e->getMessage());
        self::assertStringContainsString('2048', $e->getMessage());
        self::assertStringContainsString('overflow', $e->getMessage());
    }

    #[Test]
    public function invalidConfigurationIncludesMessage(): void
    {
        $e = OpenTelemetryException::invalidConfiguration('endpoint is required');

        self::assertStringContainsString('endpoint is required', $e->getMessage());
        self::assertStringContainsString('Invalid OpenTelemetry configuration', $e->getMessage());
    }
}
