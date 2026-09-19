<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Evidence\AiTransparencyDrillInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\AiGovernance\AiGovernanceServiceProvider;
use Pulsar\Extension\AiGovernance\Config\AiGovernanceConfig;
use Pulsar\Extension\AiGovernance\Contracts\AiAuditLoggerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiDataGovernanceInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiLifecycleManagerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiTransparencyInterface;
use Pulsar\Extension\AiGovernance\Contracts\ExplainabilityInterface;

use function sprintf;

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
        self::assertContains(AiTransparencyInterface::class, $provides);
        // A FRAMEWORK contract, answered here. The compliance assessor cannot call
        // AiTransparencyInterface itself, so an extension that bound the Article 50
        // subsystem and not this seam would leave `ai_transparency_exercised`
        // reporting that nothing ran on a deployment where everything works.
        self::assertContains(AiTransparencyDrillInterface::class, $provides);
    }

    /**
     * Everything the provider ANNOUNCES it provides, it binds.
     *
     * This used to also assert `exactly(7)` calls to `bind()`, which is not a
     * property of the provider — it is a count of the lines in its `register()`
     * — and it broke the moment the provider grew an eighth binding it should
     * have had all along. `provides()` is the list that means something: the
     * deferred-provider registry indexes on it, so an id announced there and not
     * bound here is a service the container promises and cannot deliver.
     * Comparing the two lists checks that, and needs no maintenance when the
     * provider grows.
     */
    #[Test]
    public function registerBindsEverythingProvidesAnnounces(): void
    {
        $bindings = [];
        $container = $this->createStub(ContainerInterface::class);
        $container->method('bind')
            ->willReturnCallback(function (string $id) use (&$bindings): void {
                $bindings[] = $id;
            });

        $provider = new AiGovernanceServiceProvider();
        $provider->register($container);

        foreach ($provider->provides() as $announced) {
            self::assertContains(
                $announced,
                $bindings,
                sprintf('provides() announces "%s" but register() does not bind it', $announced),
            );
        }

        self::assertContains(AiGovernanceConfig::class, $bindings);
        self::assertContains(AiLifecycleManagerInterface::class, $bindings);
    }
}
