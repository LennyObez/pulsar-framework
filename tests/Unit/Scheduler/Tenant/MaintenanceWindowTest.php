<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler\Tenant;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\Tenant\MaintenanceWindow;

#[CoversClass(MaintenanceWindow::class)]
final class MaintenanceWindowTest extends TestCase
{
    #[Test]
    public function isActiveDuringWindow(): void
    {
        $window = new MaintenanceWindow(
            start: new DateTimeImmutable('2026-03-01 02:00:00'),
            end: new DateTimeImmutable('2026-03-01 04:00:00'),
            reason: 'Scheduled maintenance',
        );

        self::assertTrue($window->isActive(new DateTimeImmutable('2026-03-01 03:00:00')));
    }

    #[Test]
    public function isInactiveBeforeWindow(): void
    {
        $window = new MaintenanceWindow(
            start: new DateTimeImmutable('2026-03-01 02:00:00'),
            end: new DateTimeImmutable('2026-03-01 04:00:00'),
            reason: 'Scheduled maintenance',
        );

        self::assertFalse($window->isActive(new DateTimeImmutable('2026-03-01 01:00:00')));
    }

    #[Test]
    public function isInactiveAfterWindow(): void
    {
        $window = new MaintenanceWindow(
            start: new DateTimeImmutable('2026-03-01 02:00:00'),
            end: new DateTimeImmutable('2026-03-01 04:00:00'),
            reason: 'Scheduled maintenance',
        );

        self::assertFalse($window->isActive(new DateTimeImmutable('2026-03-01 05:00:00')));
    }

    #[Test]
    public function isActiveAtBoundaryStart(): void
    {
        $window = new MaintenanceWindow(
            start: new DateTimeImmutable('2026-03-01 02:00:00'),
            end: new DateTimeImmutable('2026-03-01 04:00:00'),
            reason: 'Scheduled maintenance',
        );

        self::assertTrue($window->isActive(new DateTimeImmutable('2026-03-01 02:00:00')));
    }

    #[Test]
    public function isActiveAtBoundaryEnd(): void
    {
        $window = new MaintenanceWindow(
            start: new DateTimeImmutable('2026-03-01 02:00:00'),
            end: new DateTimeImmutable('2026-03-01 04:00:00'),
            reason: 'Scheduled maintenance',
        );

        self::assertTrue($window->isActive(new DateTimeImmutable('2026-03-01 04:00:00')));
    }

    #[Test]
    public function reasonAccessible(): void
    {
        $window = new MaintenanceWindow(
            start: new DateTimeImmutable('2026-03-01 02:00:00'),
            end: new DateTimeImmutable('2026-03-01 04:00:00'),
            reason: 'Database migration',
        );

        self::assertSame('Database migration', $window->reason);
    }
}
