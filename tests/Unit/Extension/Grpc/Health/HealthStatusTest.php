<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Health\HealthStatus;

#[CoversClass(HealthStatus::class)]
final class HealthStatusTest extends TestCase
{
    #[Test]
    public function statusValues(): void
    {
        self::assertSame(0, HealthStatus::Unknown->value);
        self::assertSame(1, HealthStatus::Serving->value);
        self::assertSame(2, HealthStatus::NotServing->value);
        self::assertSame(3, HealthStatus::ServiceUnknown->value);
    }

    #[Test]
    public function allCasesExist(): void
    {
        self::assertCount(4, HealthStatus::cases());
    }
}
