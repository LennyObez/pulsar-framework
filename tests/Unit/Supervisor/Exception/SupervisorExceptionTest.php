<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Supervisor\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Supervisor\Exception\SupervisorException;
use RuntimeException;

#[CoversClass(SupervisorException::class)]
final class SupervisorExceptionTest extends TestCase
{
    #[Test]
    public function it_extends_runtime_exception(): void
    {
        $exception = SupervisorException::preflightFailed('memory', 'out of memory');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function it_creates_preflight_failed_exception(): void
    {
        $exception = SupervisorException::preflightFailed('disk_space', 'Insufficient disk space');

        self::assertStringContainsString('Preflight check "disk_space" failed', $exception->getMessage());
        self::assertStringContainsString('Insufficient disk space', $exception->getMessage());
    }

    #[Test]
    public function it_creates_invariant_violation_exception(): void
    {
        $exception = SupervisorException::invariantViolation('db_connectivity', 'Connection lost');

        self::assertStringContainsString('Invariant violation in "db_connectivity"', $exception->getMessage());
        self::assertStringContainsString('Connection lost', $exception->getMessage());
    }

    #[Test]
    public function it_creates_recovery_failed_exception(): void
    {
        $exception = SupervisorException::recoveryFailed('job-123', 'Dead letter queue full');

        self::assertStringContainsString('Recovery failed for job "job-123"', $exception->getMessage());
        self::assertStringContainsString('Dead letter queue full', $exception->getMessage());
    }

    #[Test]
    public function it_preserves_special_characters_in_check_name(): void
    {
        $exception = SupervisorException::preflightFailed('check/with-special.chars', 'reason');

        self::assertStringContainsString('check/with-special.chars', $exception->getMessage());
    }

    #[Test]
    public function it_preserves_special_characters_in_reason(): void
    {
        $exception = SupervisorException::invariantViolation('check', 'Error: "unexpected" state <bad>');

        self::assertStringContainsString('Error: "unexpected" state <bad>', $exception->getMessage());
    }

    #[Test]
    public function it_preserves_special_characters_in_job_id(): void
    {
        $exception = SupervisorException::recoveryFailed('job-uuid-123-456', 'timeout');

        self::assertStringContainsString('job-uuid-123-456', $exception->getMessage());
    }

    #[Test]
    public function it_handles_empty_strings(): void
    {
        $preflight = SupervisorException::preflightFailed('', '');
        self::assertStringContainsString('Preflight check "" failed: ', $preflight->getMessage());

        $invariant = SupervisorException::invariantViolation('', '');
        self::assertStringContainsString('Invariant violation in "": ', $invariant->getMessage());

        $recovery = SupervisorException::recoveryFailed('', '');
        self::assertStringContainsString('Recovery failed for job "": ', $recovery->getMessage());
    }
}
