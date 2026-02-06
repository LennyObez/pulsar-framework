<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Exception\QueueException;
use RuntimeException;

#[CoversClass(QueueException::class)]
final class QueueExceptionTest extends TestCase
{
    #[Test]
    public function it_extends_runtime_exception(): void
    {
        $exception = QueueException::driverNotConfigured('redis');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function driver_not_configured_includes_driver_name(): void
    {
        $exception = QueueException::driverNotConfigured('redis');

        self::assertStringContainsString('redis', $exception->getMessage());
        self::assertStringContainsString('not configured', $exception->getMessage());
    }

    #[Test]
    public function job_not_found_includes_job_id(): void
    {
        $exception = QueueException::jobNotFound('abc-123');

        self::assertStringContainsString('abc-123', $exception->getMessage());
        self::assertStringContainsString('not found', $exception->getMessage());
    }

    #[Test]
    public function job_failed_includes_id_and_reason(): void
    {
        $exception = QueueException::jobFailed('job-456', 'Connection refused');

        self::assertStringContainsString('job-456', $exception->getMessage());
        self::assertStringContainsString('Connection refused', $exception->getMessage());
    }

    #[Test]
    public function max_attempts_exceeded_includes_id_and_count(): void
    {
        $exception = QueueException::maxAttemptsExceeded('job-789', 5);

        self::assertStringContainsString('job-789', $exception->getMessage());
        self::assertStringContainsString('5', $exception->getMessage());
        self::assertStringContainsString('maximum attempts', $exception->getMessage());
    }

    #[Test]
    public function serialization_failed_includes_class_name(): void
    {
        $exception = QueueException::serializationFailed('App\\Jobs\\BrokenJob');

        self::assertStringContainsString('App\\Jobs\\BrokenJob', $exception->getMessage());
        self::assertStringContainsString('serialize', $exception->getMessage());
    }
}
