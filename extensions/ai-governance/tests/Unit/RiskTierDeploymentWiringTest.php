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
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extensibility\ExtensionConfigRegistry;
use Pulsar\Extension\AiGovernance\AiGovernanceServiceProvider;
use Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiLifecycleManagerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface;
use Pulsar\Extension\AiGovernance\Contracts\HumanOversightInterface;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\ModelCard;
use Pulsar\Extension\AiGovernance\Enum\AiActorRole;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Extension\AiGovernance\Internal\AiAuditLogger;
use Pulsar\Extension\AiGovernance\Internal\AiLifecycleManager;
use Pulsar\Extension\AiGovernance\Internal\Gate\HighRiskObligationsGate;
use Pulsar\Extension\AiGovernance\Internal\Monitoring\GovernanceConformityHook;
use Pulsar\Extension\AiGovernance\Internal\MonitoringHookRegistry;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryHumanOversight;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryImpactAssessmentStore;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryModelRegistry;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryMonitoringRecordStore;
use Pulsar\Extension\AiGovernance\Oversight\OversightAssignment;
use Pulsar\Extension\AiGovernance\Oversight\OversightCapability;
use Pulsar\Extension\AiGovernance\Tests\Support\AiGovernanceSchema;

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
        // The role is declared, because the Act attaches Articles 9, 11 and
        // 72(3) to a PROVIDER. Without it the gate refuses for a different
        // reason and this case would stop measuring what it is named for.
        $container = $this->boot(['actor_role' => 'provider']);
        $this->registerModel($container, AiModelRiskLevel::High);

        /** @var AiLifecycleManagerInterface $manager */
        $manager = $container->get(AiLifecycleManagerInterface::class);

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageIsOrContains('high_risk_obligations');

        $manager->deploy('m1');
    }

    /**
     * The Article 72(3) limb is met by the hook the extension now REGISTERS, and
     * this test records that posture change rather than the refusal it replaced.
     *
     * Until rc.12 this method asserted the opposite: a high-risk model carrying an
     * impact assessment and a model card was still refused, because
     * `MonitoringHookInterface` had zero implementations anywhere in the tree and
     * the shipped wiring could therefore register none. That was a correct refusal
     * of an incorrect situation — no deployment of this framework could place a
     * high-risk system on the market at all, for a reason that was a gap in the
     * framework rather than a property of the model.
     *
     * {@see GovernanceConformityHook} is registered by the provider before any
     * gate, so the limb is satisfied by the shipped wiring. What that hook
     * monitors is the governance record of a model in service, which is a real
     * post-market monitoring measure and is deliberately not the whole of one; a
     * deployment subject to Article 72(3) owes hooks for model performance and
     * drift alongside it.
     */
    #[Test]
    public function theArticle72LimbIsMetByTheHookTheShippedWiringRegisters(): void
    {
        // The role is declared, because the Act attaches Articles 9, 11 and
        // 72(3) to a PROVIDER. Without it the gate refuses for a different
        // reason and this case would stop measuring what it is named for.
        $container = $this->boot(['actor_role' => 'provider']);
        $this->registerModel($container, AiModelRiskLevel::High, card: $this->card());

        /** @var AiImpactAssessmentInterface $assessments */
        $assessments = $container->get(AiImpactAssessmentInterface::class);
        $assessments->assess('m1');

        /** @var AiLifecycleManagerInterface $manager */
        $manager = $container->get(AiLifecycleManagerInterface::class);

        self::assertSame(AiModelStatus::Production, $manager->deploy('m1')->status);

        // And the hook that unblocked it is a hook that runs: monitoring the
        // deployed model produces a result naming it. A registration that
        // satisfied the gate and then measured nothing would be the same
        // substitution the gate exists to refuse.
        $results = $manager->monitor('m1');

        self::assertNotSame([], $results);
        self::assertSame(GovernanceConformityHook::NAME, $results[0]->hookName);
    }

    /**
     * The refusal itself is still real, and it is still spelled Article 72(3):
     * a manager whose hook registry is empty refuses a high-risk model that
     * carries both of its documentary obligations.
     */
    #[Test]
    public function aHighRiskModelWithoutMonitoringIsRefusedDespiteDocumentationAndAssessment(): void
    {
        $assessments = new InMemoryImpactAssessmentStore();
        $assessments->assess('m1');

        $registry = new InMemoryModelRegistry();
        $registry->register($this->model(AiModelRiskLevel::High, $this->card()));

        $manager = new AiLifecycleManager(
            $registry,
            new AiAuditLogger(new NullAuditLogger(), false),
            new MonitoringHookRegistry(),
            new InMemoryMonitoringRecordStore(),
        );

        $manager->addDeploymentGate(new HighRiskObligationsGate(
            $assessments,
            new MonitoringHookRegistry(),
            new InMemoryHumanOversight(),
            AiActorRole::Provider,
        ));

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageIsOrContains('Article 72');

        $manager->deploy('m1');
    }

    #[Test]
    public function aHighRiskModelCarryingAllThreeObligationsDeploys(): void
    {
        // The role is declared, because the Act attaches Articles 9, 11 and
        // 72(3) to a PROVIDER. Without it the gate refuses for a different
        // reason and this case would stop measuring what it is named for.
        $container = $this->boot(['actor_role' => 'provider']);
        $this->registerModel($container, AiModelRiskLevel::High, card: $this->card());

        /** @var AiImpactAssessmentInterface $assessments */
        $assessments = $container->get(AiImpactAssessmentInterface::class);
        $assessments->assess('m1');

        /** @var AiLifecycleManagerInterface $manager */
        $manager = $container->get(AiLifecycleManagerInterface::class);
        $manager->addMonitoringHook($this->hook());

        self::assertSame(AiModelStatus::Production, $manager->deploy('m1')->status);
    }

    /**
     * The shipped wiring refuses a high-risk deployment whose role nobody declared.
     *
     * Every provider artefact is on record here — impact assessment, model card,
     * and the monitoring hook the provider registers — so the only thing missing
     * is the answer to which obligations apply. Before rc.12 there was no such
     * thing to be missing: the gate demanded the provider set of everyone, which
     * meant a deployer was refused for duties it does not owe and was never asked
     * for the one Article 26(2) puts on it.
     */
    #[Test]
    public function aHighRiskModelIsRefusedWhileNoActorRoleIsDeclared(): void
    {
        $container = $this->boot();
        $this->registerModel($container, AiModelRiskLevel::High, card: $this->card());

        /** @var AiImpactAssessmentInterface $assessments */
        $assessments = $container->get(AiImpactAssessmentInterface::class);
        $assessments->assess('m1');

        /** @var AiLifecycleManagerInterface $manager */
        $manager = $container->get(AiLifecycleManagerInterface::class);

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageIsOrContains('declared neither role');

        $manager->deploy('m1');
    }

    /**
     * A deployer's high-risk model deploys on the Article 26(2) record alone.
     *
     * No impact assessment, no model card, no monitoring hook of its own: those
     * are the provider's duties. What this deployment must show is a named,
     * competent, authorised natural person who can stop the system, and it does.
     */
    #[Test]
    public function aDeployersHighRiskModelDeploysOnItsOversightAssignment(): void
    {
        // require_impact_assessment is an ISO 42001 house rule this extension
        // applies to every model whatever its tier, and it is switched off here so
        // that the case measures the AI Act limb rather than the house rule.
        $container = $this->boot(['actor_role' => 'deployer', 'require_impact_assessment' => false]);
        $this->registerModel($container, AiModelRiskLevel::High);

        /** @var HumanOversightInterface $oversight */
        $oversight = $container->get(HumanOversightInterface::class);
        $oversight->assign(new OversightAssignment(
            modelId: 'm1',
            overseerId: 'risk-officer-2',
            competenceBasis: 'Credit risk officer, completed the vendor model training in 2026.',
            authorityBasis: 'Named in the model risk policy as able to suspend the scoring service.',
            capabilities: OversightCapability::exercisable(),
            assignedAt: new DateTimeImmutable('2026-01-01'),
        ));

        /** @var AiLifecycleManagerInterface $manager */
        $manager = $container->get(AiLifecycleManagerInterface::class);

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

        // The shipped default for every store key is `database` since rc.12, and
        // the provider refuses to substitute memory for a missing connection —
        // silently falling back is how a deployment comes to believe it retains a
        // record it does not. So the wiring under test is booted the way a real
        // deployment boots it: with a connection carrying the schema the shipped
        // migration creates.
        $container->instance(ConnectionInterface::class, AiGovernanceSchema::connection());
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
