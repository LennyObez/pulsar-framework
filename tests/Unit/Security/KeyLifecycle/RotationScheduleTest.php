<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\KeyLifecycle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\KeyLifecycle\RotationSchedule;

#[CoversClass(RotationSchedule::class)]
final class RotationScheduleTest extends TestCase
{
    public function testConstructorAssignsProperties(): void
    {
        $schedule = new RotationSchedule(
            kid: 'master-key',
            intervalSeconds: 7776000,
            gracePeriodSeconds: 86400,
        );

        self::assertSame('master-key', $schedule->kid);
        self::assertSame(7776000, $schedule->intervalSeconds);
        self::assertSame(86400, $schedule->gracePeriodSeconds);
    }

    public function testToArray(): void
    {
        $schedule = new RotationSchedule(
            kid: 'enc-key',
            intervalSeconds: 2592000,
            gracePeriodSeconds: 3600,
        );

        $array = $schedule->toArray();

        self::assertSame('enc-key', $array['kid']);
        self::assertSame(2592000, $array['interval_seconds']);
        self::assertSame(3600, $array['grace_period_seconds']);
    }
}
