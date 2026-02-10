<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Adapter\GrpcRequestHandler;
use Pulsar\Extension\Grpc\Adapter\RoadRunnerGrpcAdapter;
use RuntimeException;

#[CoversClass(RoadRunnerGrpcAdapter::class)]
final class RoadRunnerGrpcAdapterTest extends TestCase
{
    #[Test]
    public function name_returns_roadrunner(): void
    {
        $adapter = new RoadRunnerGrpcAdapter();

        self::assertSame('roadrunner', $adapter->name());
    }

    #[Test]
    public function is_available_returns_false_outside_roadrunner(): void
    {
        // Outside a RoadRunner process, the RR environment variables are absent.
        $adapter = new RoadRunnerGrpcAdapter();

        self::assertFalse($adapter->isAvailable());
    }

    #[Test]
    public function listen_throws_when_roadrunner_not_available(): void
    {
        $adapter = new RoadRunnerGrpcAdapter();
        $handler = $this->createStub(GrpcRequestHandler::class);

        if ($adapter->isAvailable()) {
            self::markTestSkipped('RoadRunner is available; cannot test unavailability.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RoadRunner gRPC worker is not available');

        $adapter->listen('0.0.0.0', 50051, $handler);
    }

    #[Test]
    public function shutdown_is_safe_when_not_started(): void
    {
        $adapter = new RoadRunnerGrpcAdapter();

        // Shutdown before start should not throw.
        $adapter->shutdown();

        self::assertSame('roadrunner', $adapter->name());
    }
}
