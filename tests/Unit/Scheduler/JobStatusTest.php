<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\JobStatus;

#[CoversNothing]
final class JobStatusTest extends TestCase
{
    #[Test]
    public function hasFourCases(): void
    {
        self::assertCount(4, JobStatus::cases());
    }

    #[Test]
    #[DataProvider('jobStatusProvider')]
    public function backedValues(JobStatus $status, string $expected): void
    {
        self::assertSame($expected, $status->value);
    }

    /**
     * @return iterable<string, array{JobStatus, string}>
     */
    public static function jobStatusProvider(): iterable
    {
        yield 'Success' => [JobStatus::Success, 'success'];
        yield 'Failure' => [JobStatus::Failure, 'failure'];
        yield 'Skipped' => [JobStatus::Skipped, 'skipped'];
        yield 'Running' => [JobStatus::Running, 'running'];
    }

    #[Test]
    public function fromBackedValue(): void
    {
        self::assertSame(JobStatus::Running, JobStatus::from('running'));
        self::assertSame(JobStatus::Failure, JobStatus::from('failure'));
    }
}
