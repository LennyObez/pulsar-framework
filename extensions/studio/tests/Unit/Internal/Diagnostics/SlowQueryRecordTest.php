<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Internal\Diagnostics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Internal\Diagnostics\SlowQueryRecord;

final class SlowQueryRecordTest extends TestCase
{
    #[Test]
    public function overageMultiplierCalculation(): void
    {
        $record = new SlowQueryRecord(
            sql: 'select * from users',
            fingerprint: 'abc',
            durationMs: 300.0,
            thresholdMs: 100.0,
            queryType: 'SELECT',
            connectionName: 'primary',
            recordedAt: 1234567890.0,
        );

        self::assertEqualsWithDelta(3.0, $record->overageMultiplier(), 0.001);
    }

    #[Test]
    public function overageMultiplierZeroWhenThresholdIsZero(): void
    {
        $record = new SlowQueryRecord(
            sql: 'select 1',
            fingerprint: 'xyz',
            durationMs: 50.0,
            thresholdMs: 0.0,
            queryType: 'SELECT',
            connectionName: null,
            recordedAt: 1234567890.0,
        );

        self::assertSame(0.0, $record->overageMultiplier());
    }

    #[Test]
    public function propertiesAreReadonly(): void
    {
        $record = new SlowQueryRecord(
            sql: 'select * from orders where total > ?',
            fingerprint: 'fp_hash',
            durationMs: 150.0,
            thresholdMs: 100.0,
            queryType: 'SELECT',
            connectionName: 'replica',
            recordedAt: 1700000000.0,
        );

        self::assertSame('select * from orders where total > ?', $record->sql);
        self::assertSame('fp_hash', $record->fingerprint);
        self::assertSame('SELECT', $record->queryType);
        self::assertSame('replica', $record->connectionName);
        self::assertEqualsWithDelta(1700000000.0, $record->recordedAt, 0.001);
    }
}
