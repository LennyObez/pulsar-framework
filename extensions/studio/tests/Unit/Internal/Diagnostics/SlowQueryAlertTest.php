<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Internal\Diagnostics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Internal\Diagnostics\SlowQueryAlert;

final class SlowQueryAlertTest extends TestCase
{
    #[Test]
    public function queriesBelowThresholdAreNotRecorded(): void
    {
        $alert = new SlowQueryAlert(thresholdMs: 100.0);

        $alert->evaluate('SELECT * FROM users WHERE id = 1', 50.0);
        $alert->evaluate('SELECT * FROM orders', 99.9);

        self::assertFalse($alert->hasAlerts());
        self::assertSame(0, $alert->count());
    }

    #[Test]
    public function queriesAtOrAboveThresholdAreRecorded(): void
    {
        $alert = new SlowQueryAlert(thresholdMs: 100.0);

        $alert->evaluate('SELECT * FROM users WHERE id = 1', 100.0);
        $alert->evaluate('SELECT * FROM orders', 150.0);

        self::assertTrue($alert->hasAlerts());
        self::assertSame(2, $alert->count());
    }

    #[Test]
    public function slowestReturnsOrderedByDuration(): void
    {
        $alert = new SlowQueryAlert(thresholdMs: 10.0);

        $alert->evaluate('SELECT 1', 50.0);
        $alert->evaluate('SELECT 2', 200.0);
        $alert->evaluate('SELECT 3', 100.0);

        $slowest = $alert->slowest(2);
        self::assertCount(2, $slowest);
        self::assertEqualsWithDelta(200.0, $slowest[0]->durationMs, 0.001);
        self::assertEqualsWithDelta(100.0, $slowest[1]->durationMs, 0.001);
    }

    #[Test]
    public function connectionNameIsStored(): void
    {
        $alert = new SlowQueryAlert(thresholdMs: 10.0);

        $alert->evaluate('SELECT * FROM users', 50.0, 'primary');

        $alerts = $alert->alerts();
        self::assertSame('primary', $alerts[0]->connectionName);
    }

    #[Test]
    public function resetClearsAlerts(): void
    {
        $alert = new SlowQueryAlert(thresholdMs: 10.0);

        $alert->evaluate('SELECT * FROM users', 50.0);
        self::assertTrue($alert->hasAlerts());

        $alert->reset();
        self::assertFalse($alert->hasAlerts());
        self::assertSame(0, $alert->count());
    }

    #[Test]
    public function toArrayExportsCorrectStructure(): void
    {
        $alert = new SlowQueryAlert(thresholdMs: 50.0);

        $alert->evaluate('SELECT * FROM users WHERE id = 1', 75.0, 'default');

        $array = $alert->toArray();
        self::assertCount(1, $array);
        self::assertArrayHasKey('sql', $array[0]);
        self::assertArrayHasKey('fingerprint', $array[0]);
        self::assertArrayHasKey('duration_ms', $array[0]);
        self::assertArrayHasKey('threshold_ms', $array[0]);
        self::assertArrayHasKey('query_type', $array[0]);
        self::assertArrayHasKey('connection_name', $array[0]);
        self::assertSame('SELECT', $array[0]['query_type']);
        self::assertSame('default', $array[0]['connection_name']);
    }

    #[Test]
    public function maxAlertsLimitsCollection(): void
    {
        $alert = new SlowQueryAlert(thresholdMs: 1.0, maxAlerts: 3);

        for ($i = 0; $i < 10; $i++) {
            $alert->evaluate('SELECT ' . $i, 5.0);
        }

        self::assertSame(3, $alert->count());
    }

    #[Test]
    public function thresholdMsAccessor(): void
    {
        $alert = new SlowQueryAlert(thresholdMs: 42.5);
        self::assertEqualsWithDelta(42.5, $alert->thresholdMs(), 0.001);
    }
}
