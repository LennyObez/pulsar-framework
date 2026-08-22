<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\ReloadableRuntimeInterface;
use Pulsar\Runtime\RuntimeType;
use Pulsar\Runtime\Worker\WorkerHealthStatus;
use Pulsar\Runtime\Worker\WorkerInfo;
use Pulsar\Runtime\Worker\WorkerState;
use ReflectionClass;

#[CoversNothing]
final class ReloadableRuntimeInterfaceTest extends TestCase
{
    #[Test]
    public function it_extends_runtime_interface(): void
    {
        $reflection = new ReflectionClass(ReloadableRuntimeInterface::class);

        self::assertTrue($reflection->isInterface());

        $parentInterfaces = $reflection->getInterfaceNames();
        self::assertContains('Pulsar\Runtime\RuntimeInterface', $parentInterfaces);
    }

    #[Test]
    public function it_declares_reload_method(): void
    {
        $reflection = new ReflectionClass(ReloadableRuntimeInterface::class);

        self::assertTrue($reflection->hasMethod('reload'));

        $method = $reflection->getMethod('reload');
        self::assertTrue($method->isPublic());
        self::assertSame('void', (string) $method->getReturnType());
    }

    #[Test]
    public function it_declares_health_status_method(): void
    {
        $reflection = new ReflectionClass(ReloadableRuntimeInterface::class);

        self::assertTrue($reflection->hasMethod('healthStatus'));

        $method = $reflection->getMethod('healthStatus');
        self::assertTrue($method->isPublic());
        self::assertSame(WorkerHealthStatus::class, (string) $method->getReturnType());
    }

    #[Test]
    public function it_declares_worker_info_method(): void
    {
        $reflection = new ReflectionClass(ReloadableRuntimeInterface::class);

        self::assertTrue($reflection->hasMethod('workerInfo'));

        $method = $reflection->getMethod('workerInfo');
        self::assertTrue($method->isPublic());
        self::assertSame(WorkerInfo::class, (string) $method->getReturnType());
    }

    #[Test]
    public function mock_implementation_fulfills_contract(): void
    {
        $workerInfo = new WorkerInfo(
            pid: 1,
            startedAt: 0,
            requestCount: 0,
            memoryUsageMb: 0,
            state: WorkerState::Ready,
            runtimeType: RuntimeType::Persistent,
        );

        $mock = $this->createStub(ReloadableRuntimeInterface::class);
        $mock->method('healthStatus')->willReturn(WorkerHealthStatus::Healthy);
        $mock->method('workerInfo')->willReturn($workerInfo);

        self::assertSame(WorkerHealthStatus::Healthy, $mock->healthStatus());
        self::assertSame($workerInfo, $mock->workerInfo());
    }
}
