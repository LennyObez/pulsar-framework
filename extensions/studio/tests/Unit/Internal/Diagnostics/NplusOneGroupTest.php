<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Internal\Diagnostics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Internal\Diagnostics\NplusOneGroup;

final class NplusOneGroupTest extends TestCase
{
    #[Test]
    public function averageDurationCalculation(): void
    {
        $group = new NplusOneGroup(
            fingerprint: 'abc123',
            normalizedSql: 'select * from users where id = ?',
            count: 4,
            totalDurationMs: 10.0,
        );

        self::assertEqualsWithDelta(2.5, $group->averageDurationMs(), 0.001);
    }

    #[Test]
    public function averageDurationZeroWhenCountIsZero(): void
    {
        $group = new NplusOneGroup(
            fingerprint: 'abc123',
            normalizedSql: 'select * from users',
            count: 0,
            totalDurationMs: 0.0,
        );

        self::assertSame(0.0, $group->averageDurationMs());
    }

    #[Test]
    public function propertiesAreAccessible(): void
    {
        $group = new NplusOneGroup(
            fingerprint: 'fp_hash',
            normalizedSql: 'select * from orders where user_id = ?',
            count: 10,
            totalDurationMs: 45.5,
        );

        self::assertSame('fp_hash', $group->fingerprint);
        self::assertSame('select * from orders where user_id = ?', $group->normalizedSql);
        self::assertSame(10, $group->count);
        self::assertEqualsWithDelta(45.5, $group->totalDurationMs, 0.001);
    }
}
