<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\NullAuditLogger;
use Pulsar\Container\Container;
use Pulsar\Extensibility\ExtensionConfigRegistry;
use Pulsar\Extension\AiGovernance\AiGovernanceServiceProvider;
use Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiLifecycleManagerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\ModelCard;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;

use function array_column;

/**
 * The risk tier has to decide something in the wiring an operator actually gets,
 * not only in the gate classes read in isolation.
 *
 * Every case here resolves AiLifecycleManagerInterface from a container the
 * shipped service provider registered, so a gate that exists and is never wired
 * fails these tests exactly as a gate that is wired and does nothing would.
 */
#[CoversClass(AiGovernanceServiceProvider::class)]
final class RiskTierDeploymentWiringTest extends TestCase
{
    #[Test]
    public function aProhibitedModelIsRefusedProductionByTheShippedWiring(): void
    {
        $container = $this->boot();
        $this->registerModel($container, AiModelRiskLevel::Unacceptable);

        /** @var AiLifecycleManagerInterface $manager */
        $manager = $container->get(AiLifecycleManagerInterface::class);

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageIsOrContains('prohibited_practice');

        $manager->deploy('m1');
    }

    #[Test]
    public function theRefusalNamesTheArticleThatProhibitsThePractice(): void
    {
        $container = $this->boot();
        $this->registerModel($container, AiModelRiskLevel::Unacceptable);

        /** @var AiLifecycleManagerInterface $manager */
        $manager = $container->get(AiLifecycleManagerInterface::class);

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageIsOrContains('Article 5');

        $manager->deploy('m1');
    }

    #[Test]
    public function aProhibitedModelStaysOutOfProductionAfterTheRefusal(): void
    {
        $container = $this->boot();
        $this->registerModel($container, AiModelRiskLevel::Unacceptable);

        /** @var AiLifecycleManagerInterface $manager */
        $manager = $container->get(AiLifecycleManagerInterface::class);

        try {
            $manager->deploy('m1');
            self::fail('Deployment of a prohibited practice should have been refused.');
        } catch (AiGovernanceException) {
            /** @var AiModelRegistryInterface $registry */
            $registry = $container->get(AiModelRegistryInterface::class);

            self::assertSame(AiModelStatus::Staging, $registry->get('m1')?->status);
        }
    }

    #[Test]
    public function bothRiskGatesAreWiredEvenWithEveryConfigurableGateSwitchedOff(): void
    {
        $container = $this->boot([
            'require_impact_assessment' => false,
            'require_model_card' => false,
        ]);

        /** @var AiLifecycleManagerInterface $manager */
        $manager = $container->get(AiLifecycleManagerInterface::class);

        $results = $manager->evaluateGates($this->model(AiModelRiskLevel::Unacceptable));

        self::assertSame(['prohibited_practice', 'high_risk_obligations'], array_column($results, 'gate'));
        self::assertFalse($results[0]['passed']);
    }

    #[Test]
    public function theProhibitionSurvivesEveryConfigurableGateBeingSwitchedOff(): void
    {
        $container = $this->boot([
            'require_impact_assessment' => false,
            'require_model_card' => false,
        ]);
        $this->registerModel($container, AiModelRiskLevel::Unacceptable);

        /** @var AiLifecycleManagerInterface $manager */
        $manager = $container->get(AiLifecycleManagerInterface::class);

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageIsOrContains('Article 5');

        $manager->deploy('m1');
    }

    #[Test]
    public function aHighRiskModelIsRefusedUntilItCarriesItsObligations(): void
    {
        $container = $this->boot();
        $this->registerModel($container, AiModelRiskLevel::High);

        /** @var AiLifecycleManagerInterface $manager */
        $manager = $container->get(AiLifecycleManagerInterface::class);

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageIsOrContains('high_risk_obligations');

        $manager->deploy('m1');
    }

    #[Test]
    public function aHighRiskModelWithoutMonitoringIsRefusedDespiteDocumentationAndAssessment(): void
    {
        $container = $this->boot();
        $this->registerModel($container, AiModelRiskLevel::High, card: $this->card());

        /** @var AiImpactAssessmentInterface $assessments */
        $assessments = $container->get(AiImpactAssessmentInterface::class);
        $assessments->assess('m1');

        /** @var AiLifecycleManagerInterface $manager */
        $manager = $container->get(AiLifecycleManagerInterface::class);

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageIsOrContains('Article 72');

        $manager->deploy('m1');
    }

    #[Test]
    public function aHighRiskModelCarryingAllThreeObligationsDeploys(): void
    {
        $container = $this->boot();
        $this->registerModel($container, AiModelRiskLevel::High, card: $this->card());

        /** @var AiImpactAssessmentInterface $assessments */
        $assessments = $container->get(AiImpactAssessmentInterface::class);
        $assessments->assess('m1');

        /** @var AiLifecycleManagerInterface $manager */
        $manager = $container->get(AiLifecycleManagerInterface::class);
        $manager->addMonitoringHook($this->hook());

        self::assertSame(AiModelStatus::Production, $manager->deploy('m1')->status);
    }

    #[Test]
    public function aMinimalRiskModelIsNotHeldToHighRiskObligations(): void
    {
        $container = $this->boot();
        $this->registerModel($container, AiModelRiskLevel::Minimal);

        /** @var AiImpactAssessmentInterface $assessments */
        $assessments = $container->get(AiImpactAssessmentInterface::class);
        $assessments->assess('m1');

        /** @var AiLifecycleManagerInterface $manager */
        $manager = $container->get(AiLifecycleManagerInterface::class);

        self::assertSame(AiModelStatus::Production, $manager->deploy('m1')->status);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config = []): Container
    {
        $container = new Container();
        $container->instance(AuditLoggerInterface::class, new NullAuditLogger());
        $container->instance(
            ExtensionConfigRegistry::class,
            new ExtensionConfigRegistry(sections: ['ai_governance' => $config]),
        );

        new AiGovernanceServiceProvider()->register($container);

        return $container;
    }

    private function registerModel(
        Container $container,
        AiModelRiskLevel $riskLevel,
        ?ModelCard $card = null,
    ): void {
        /** @var AiModelRegistryInterface $registry */
        $registry = $container->get(AiModelRegistryInterface::class);
        $registry->register($this->model($riskLevel, $card));
    }

    private function hook(): MonitoringHookInterface
    {
        $hook = $this->createStub(MonitoringHookInterface::class);
        $hook->method('name')->willReturn('drift-check');

        return $hook;
    }

    private function card(): ModelCard
    {
        return new ModelCard(
            description: 'Credit scoring classifier.',
            intendedUse: 'Consumer creditworthiness assessment.',
        );
    }

    private function model(AiModelRiskLevel $riskLevel, ?ModelCard $card = null): AiModel
    {
        return new AiModel(
            id: 'm1',
            name: 'Test Model',
            version: '1.0.0',
            provider: 'TestProvider',
            type: 'classifier',
            riskLevel: $riskLevel,
            status: AiModelStatus::Staging,
            registeredAt: new DateTimeImmutable('2026-01-01'),
            card: $card,
        );
    }
}
