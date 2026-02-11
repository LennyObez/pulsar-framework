<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience\Repair;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\Repair\RepairResult;
use RuntimeException;

#[CoversClass(RepairResult::class)]
final class RepairResultTest extends TestCase
{
    #[Test]
    public function successfulRepair(): void
    {
        $result = new RepairResult(
            repairJobName: 'index-rebuild',
            success: true,
            description: 'Index rebuilt from scratch',
            actionsPerformed: ['Dropped stale index', 'Rebuilt from source'],
        );

        self::assertSame('index-rebuild', $result->repairJobName);
        self::assertTrue($result->success);
        self::assertSame('Index rebuilt from scratch', $result->description);
        self::assertCount(2, $result->actionsPerformed);
        self::assertNull($result->exception);
    }

    #[Test]
    public function failedRepairWithException(): void
    {
        $exception = new RuntimeException('Disk full');
        $result = new RepairResult(
            repairJobName: 'cache-warmup',
            success: false,
            description: 'Failed to warm cache',
            exception: $exception,
        );

        self::assertFalse($result->success);
        self::assertSame($exception, $result->exception);
        self::assertSame([], $result->actionsPerformed);
    }
}
