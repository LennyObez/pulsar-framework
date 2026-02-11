<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduler\Tenant;

use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\Tenant\TenantScheduleTickResult;

final class TenantScheduleTickResultTest extends TestCase
{
    public function test_construction_and_accessors(): void
    {
        $result = new TenantScheduleTickResult(
            tenantsProcessed: 5,
            jobsDispatched: 10,
            tenantsSkipped: 2,
            jobsFailed: 1,
        );

        self::assertSame(5, $result->tenantsProcessed);
        self::assertSame(10, $result->jobsDispatched);
        self::assertSame(2, $result->tenantsSkipped);
        self::assertSame(1, $result->jobsFailed);
    }
}
