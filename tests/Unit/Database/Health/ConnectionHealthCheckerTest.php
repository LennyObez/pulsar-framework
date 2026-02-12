<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Health\ConnectionHealthChecker;
use Pulsar\Database\Result;
use RuntimeException;

#[CoversClass(ConnectionHealthChecker::class)]
final class ConnectionHealthCheckerTest extends TestCase
{
    #[Test]
    public function healthyConnectionReturnsTrue(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('query')
            ->with('SELECT 1')
            ->willReturn($this->createMock(Result::class));

        $checker = new ConnectionHealthChecker();

        self::assertTrue($checker->isHealthy($connection));
    }

    #[Test]
    public function unhealthyConnectionReturnsFalse(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('query')
            ->with('SELECT 1')
            ->willThrowException(new RuntimeException('Connection lost'));

        $checker = new ConnectionHealthChecker();

        self::assertFalse($checker->isHealthy($connection));
    }

    #[Test]
    public function connectionExceptionReturnsFalse(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('query')
            ->with('SELECT 1')
            ->willThrowException(new RuntimeException('PDO error'));

        $checker = new ConnectionHealthChecker(timeoutSeconds: 1.0);

        self::assertFalse($checker->isHealthy($connection));
    }
}
