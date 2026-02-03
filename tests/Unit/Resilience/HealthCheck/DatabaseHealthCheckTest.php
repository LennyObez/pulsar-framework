<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience\HealthCheck;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Database\Result;
use Pulsar\Resilience\HealthCheck\DatabaseHealthCheck;
use Pulsar\Resilience\HealthCheck\HealthStatus;
use RuntimeException;

#[CoversClass(DatabaseHealthCheck::class)]
final class DatabaseHealthCheckTest extends TestCase
{
    #[Test]
    public function healthyWhenDatabaseResponds(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(Result::fromArrays([]));

        $manager = $this->createStub(ConnectionManagerInterface::class);
        $manager->method('connection')->willReturn($connection);

        $check = new DatabaseHealthCheck($manager);
        $result = $check->check();

        self::assertSame(HealthStatus::Healthy, $result->status);
        self::assertSame('database', $result->name);
        self::assertGreaterThanOrEqual(0, $result->responseTimeMs);
    }

    #[Test]
    public function unhealthyWhenDatabaseFails(): void
    {
        $manager = $this->createStub(ConnectionManagerInterface::class);
        $manager->method('connection')->willThrowException(new RuntimeException('Connection refused'));

        $check = new DatabaseHealthCheck($manager);
        $result = $check->check();

        self::assertSame(HealthStatus::Unhealthy, $result->status);
        self::assertStringContainsString('Connection refused', $result->message);
    }

    #[Test]
    public function usesSpecificConnectionName(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturn(Result::fromArrays([]));

        $manager = $this->createMock(ConnectionManagerInterface::class);
        $manager->expects(self::once())
            ->method('connection')
            ->with('readonly')
            ->willReturn($connection);

        $check = new DatabaseHealthCheck($manager, 'readonly');
        $result = $check->check();

        self::assertSame(HealthStatus::Healthy, $result->status);
    }

    #[Test]
    public function nameReturnsDatabase(): void
    {
        $manager = $this->createStub(ConnectionManagerInterface::class);
        $check = new DatabaseHealthCheck($manager);

        self::assertSame('database', $check->getName());
    }
}
