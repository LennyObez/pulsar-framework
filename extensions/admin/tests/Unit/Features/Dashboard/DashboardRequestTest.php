<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Features\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Features\Dashboard\DashboardRequest;

#[CoversClass(DashboardRequest::class)]
final class DashboardRequestTest extends TestCase
{
    #[Test]
    public function constructor_creates_instance(): void
    {
        $request = new DashboardRequest();

        self::assertInstanceOf(DashboardRequest::class, $request);
    }
}
