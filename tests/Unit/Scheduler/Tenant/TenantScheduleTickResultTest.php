<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Scheduler\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Scheduler\Tenant\TenantScheduleTickResult;

#[CoversClass(TenantScheduleTickResult::class)]
final class TenantScheduleTickResultTest extends TestCase
{
    #[Test]
    public function constructionAndAccessors(): void
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
