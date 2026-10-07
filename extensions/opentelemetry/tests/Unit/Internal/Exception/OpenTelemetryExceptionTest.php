<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Exception\OpenTelemetryException;
use RuntimeException;

#[CoversClass(OpenTelemetryException::class)]
final class OpenTelemetryExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = new OpenTelemetryException('test');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function exportFailed(): void
    {
        $exception = OpenTelemetryException::exportFailed('traces', 'connection refused');

        self::assertSame('Failed to export traces: connection refused', $exception->getMessage());
    }

    #[Test]
    public function queueOverflow(): void
    {
        $exception = OpenTelemetryException::queueOverflow('metrics', 2048);

        self::assertSame(
            'metrics queue overflow: max queue size 2048 reached, dropping oldest items',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function invalidConfiguration(): void
    {
        $exception = OpenTelemetryException::invalidConfiguration('endpoint is required');

        self::assertSame(
            'Invalid OpenTelemetry configuration: endpoint is required',
            $exception->getMessage(),
        );
    }
}
