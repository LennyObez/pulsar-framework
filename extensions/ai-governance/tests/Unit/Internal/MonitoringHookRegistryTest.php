<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface;
use Pulsar\Extension\AiGovernance\Internal\MonitoringHookRegistry;
use Pulsar\Extension\AiGovernance\Internal\MonitoringHookRegistryInterface;

#[CoversClass(MonitoringHookRegistry::class)]
final class MonitoringHookRegistryTest extends TestCase
{
    #[Test]
    public function startsEmpty(): void
    {
        $registry = new MonitoringHookRegistry();

        self::assertTrue($registry->isEmpty());
        self::assertSame([], $registry->all());
    }

    #[Test]
    public function reportsHooksInRegistrationOrder(): void
    {
        $registry = new MonitoringHookRegistry();
        $first = $this->hook('drift-check');
        $second = $this->hook('bias-check');

        $registry->add($first);
        $registry->add($second);

        self::assertFalse($registry->isEmpty());
        self::assertSame([$first, $second], $registry->all());
    }

    /**
     * The shipped registry is one implementation of a contract, not the contract.
     */
    #[Test]
    public function isReachableAsAContractRatherThanOnlyAsItself(): void
    {
        self::assertInstanceOf(MonitoringHookRegistryInterface::class, new MonitoringHookRegistry());
    }

    private function hook(string $name): MonitoringHookInterface
    {
        $hook = $this->createStub(MonitoringHookInterface::class);
        $hook->method('name')->willReturn($name);

        return $hook;
    }
}
