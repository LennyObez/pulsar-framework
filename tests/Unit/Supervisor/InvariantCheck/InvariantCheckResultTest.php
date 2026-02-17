<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Supervisor\InvariantCheck;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Supervisor\InvariantCheck\InvariantCheckResult;

#[CoversClass(InvariantCheckResult::class)]
final class InvariantCheckResultTest extends TestCase
{
    #[Test]
    public function passedResultWithDefaults(): void
    {
        $result = new InvariantCheckResult(passed: true, message: 'All good');

        self::assertTrue($result->passed);
        self::assertSame('All good', $result->message);
        self::assertSame([], $result->findings);
    }

    #[Test]
    public function failedResultWithFindings(): void
    {
        $result = new InvariantCheckResult(
            passed: false,
            message: 'Queue depth exceeded',
            findings: ['queue_a: 1500', 'queue_b: 2000'],
        );

        self::assertFalse($result->passed);
        self::assertSame('Queue depth exceeded', $result->message);
        self::assertCount(2, $result->findings);
        self::assertSame('queue_a: 1500', $result->findings[0]);
    }
}
