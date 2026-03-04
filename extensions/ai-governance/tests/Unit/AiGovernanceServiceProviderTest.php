<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\AiGovernance\AiGovernanceServiceProvider;
use Pulsar\Extension\AiGovernance\Config\AiGovernanceConfig;
use Pulsar\Extension\AiGovernance\Contracts\AiAuditLoggerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiDataGovernanceInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiLifecycleManagerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface;
use Pulsar\Extension\AiGovernance\Contracts\ExplainabilityInterface;

#[CoversClass(AiGovernanceServiceProvider::class)]
final class AiGovernanceServiceProviderTest extends TestCase
{
    #[Test]
    public function providesListsAllServices(): void
    {
        $provider = new AiGovernanceServiceProvider();
        $provides = $provider->provides();

        self::assertContains(AiGovernanceConfig::class, $provides);
        self::assertContains(AiModelRegistryInterface::class, $provides);
        self::assertContains(AiImpactAssessmentInterface::class, $provides);
        self::assertContains(AiAuditLoggerInterface::class, $provides);
        self::assertContains(AiDataGovernanceInterface::class, $provides);
        self::assertContains(ExplainabilityInterface::class, $provides);
        self::assertContains(AiLifecycleManagerInterface::class, $provides);
    }

    #[Test]
    public function registerBindsAllServices(): void
    {
        $bindings = [];
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::exactly(7))
            ->method('bind')
            ->willReturnCallback(function (string $id) use (&$bindings): void {
                $bindings[] = $id;
            });

        $provider = new AiGovernanceServiceProvider();
        $provider->register($container);

        self::assertContains(AiGovernanceConfig::class, $bindings);
        self::assertContains(AiModelRegistryInterface::class, $bindings);
        self::assertContains(AiImpactAssessmentInterface::class, $bindings);
        self::assertContains(AiAuditLoggerInterface::class, $bindings);
        self::assertContains(AiDataGovernanceInterface::class, $bindings);
        self::assertContains(ExplainabilityInterface::class, $bindings);
        self::assertContains(AiLifecycleManagerInterface::class, $bindings);
    }
}
