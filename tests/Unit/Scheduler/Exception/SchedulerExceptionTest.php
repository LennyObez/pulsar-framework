<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\Exception\SchedulerException;
use RuntimeException;

#[CoversClass(SchedulerException::class)]
final class SchedulerExceptionTest extends TestCase
{
    #[Test]
    public function job_not_found(): void
    {
        $exception = SchedulerException::jobNotFound('daily-cleanup');

        self::assertStringContainsString('daily-cleanup', $exception->getMessage());
        self::assertStringContainsString('not found', $exception->getMessage());
    }

    #[Test]
    public function execution_timeout(): void
    {
        $exception = SchedulerException::executionTimeout('heavy-report', 300);

        self::assertStringContainsString('heavy-report', $exception->getMessage());
        self::assertStringContainsString('300', $exception->getMessage());
        self::assertStringContainsString('execution time', $exception->getMessage());
    }

    #[Test]
    public function invalid_cron_expression(): void
    {
        $exception = SchedulerException::invalidCronExpression('* * *', 'too few fields');

        self::assertStringContainsString('* * *', $exception->getMessage());
        self::assertStringContainsString('too few fields', $exception->getMessage());
    }

    #[Test]
    public function duplicate_job(): void
    {
        $exception = SchedulerException::duplicateJob('my-job');

        self::assertStringContainsString('my-job', $exception->getMessage());
        self::assertStringContainsString('already registered', $exception->getMessage());
    }

    #[Test]
    public function extends_runtime_exception(): void
    {
        $exception = SchedulerException::jobNotFound('test');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }
}
