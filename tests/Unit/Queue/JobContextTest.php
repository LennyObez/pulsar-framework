<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\JobContext;

#[CoversClass(JobContext::class)]
final class JobContextTest extends TestCase
{
    #[Test]
    public function it_stores_all_properties(): void
    {
        $context = new JobContext(
            jobId: 'ctx-job-001',
            queue: 'reports',
            attempt: 2,
            maxAttempts: 5,
        );

        self::assertSame('ctx-job-001', $context->jobId);
        self::assertSame('reports', $context->queue);
        self::assertSame(2, $context->attempt);
        self::assertSame(5, $context->maxAttempts);
    }

    #[Test]
    public function it_accepts_first_attempt(): void
    {
        $context = new JobContext(
            jobId: 'ctx-job-002',
            queue: 'default',
            attempt: 1,
            maxAttempts: 3,
        );

        self::assertSame(1, $context->attempt);
    }

    #[Test]
    public function it_accepts_max_attempt_equal_to_attempt(): void
    {
        $context = new JobContext(
            jobId: 'ctx-job-003',
            queue: 'default',
            attempt: 3,
            maxAttempts: 3,
        );

        self::assertSame(3, $context->attempt);
        self::assertSame(3, $context->maxAttempts);
    }
}
