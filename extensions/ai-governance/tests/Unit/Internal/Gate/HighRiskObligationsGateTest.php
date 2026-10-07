<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Internal\Gate;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\ModelCard;
use Pulsar\Extension\AiGovernance\Enum\AiActorRole;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Internal\Gate\HighRiskObligationsGate;
use Pulsar\Extension\AiGovernance\Internal\MonitoringHookRegistry;
use Pulsar\Extension\AiGovernance\Internal\MonitoringHookRegistryInterface;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryHumanOversight;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryImpactAssessmentStore;
use Pulsar\Extension\AiGovernance\Oversight\OversightAssignment;
use Pulsar\Extension\AiGovernance\Oversight\OversightCapability;

/**
 * The gate enforces the obligations that apply to THIS deployment, not all of them.
 *
 * The provider-limb cases below are the ones this file has always carried, and
 * they now run against a deployment that has declared itself a provider. That is
 * not a cosmetic change to the fixture: the gate used to demand the Article 9,
 * 11 and 72(3) artefacts of every high-risk model whoever was deploying it, so
 * these cases were passing for a deployer too, and passing wrongly. The deployer
 * and undeclared cases are what that omission was hiding.
 */
#[CoversClass(HighRiskObligationsGate::class)]
final class HighRiskObligationsGateTest extends TestCase
{
    private InMemoryImpactAssessmentStore $assessments;
    private MonitoringHookRegistry $monitoringHooks;
    private InMemoryHumanOversight $oversight;
    private HighRiskObligationsGate $gate;

    protected function setUp(): void
    {
        $this->assessments = new InMemoryImpactAssessmentStore();
        $this->monitoringHooks = new MonitoringHookRegistry();
        $this->oversight = new InMemoryHumanOversight();
        $this->gate = $this->gateFor(AiActorRole::Provider);
    }

    // --- The provider limb: Articles 9, 11 and 72(3) --------------------------

    #[Test]
    public function refusesAProvidersHighRiskModelCarryingNoneOfTheObligations(): void
    {
        self::assertFalse($this->gate->evaluate($this->model(AiModelRiskLevel::High)));

        $reason = $this->gate->failureReason();
        self::assertStringContainsString('Article 9', $reason);
        self::assertStringContainsString('Article 11', $reason);
        self::assertStringContainsString('Article 72', $reason);
    }

    #[Test]
    public function refusesAProvidersHighRiskModelWithNoImpactAssessment(): void
    {
        $this->monitoringHooks->add($this->hook());

        self::assertFalse($this->gate->evaluate($this->model(AiModelRiskLevel::High, card: $this->card())));

        $reason = $this->gate->failureReason();
        self::assertStringContainsString('Article 9', $reason);
        self::assertStringNotContainsString('Article 11', $reason);
        self::assertStringNotContainsString('Article 72', $reason);
    }

    #[Test]
    public function refusesAProvidersHighRiskModelWithNoTechnicalDocumentation(): void
    {
        $this->assessments->assess('model-1');
        $this->monitoringHooks->add($this->hook());

        self::assertFalse($this->gate->evaluate($this->model(AiModelRiskLevel::High)));

        $reason = $this->gate->failureReason();
        self::assertStringContainsString('Article 11', $reason);
        self::assertStringNotContainsString('Article 9,', $reason);
        self::assertStringNotContainsString('Article 72', $reason);
    }

    #[Test]
    public function refusesAProvidersHighRiskModelWithNoPostMarketMonitoring(): void
    {
        $this->assessments->assess('model-1');

        self::assertFalse($this->gate->evaluate($this->model(AiModelRiskLevel::High, card: $this->card())));

        $reason = $this->gate->failureReason();
        self::assertStringContainsString('Article 72', $reason);
        self::assertStringNotContainsString('Article 11', $reason);
    }

    #[Test]
    public function passesAProvidersHighRiskModelCarryingAllThree(): void
    {
        $this->assessments->assess('model-1');
        $this->monitoringHooks->add($this->hook());

        self::assertTrue($this->gate->evaluate($this->model(AiModelRiskLevel::High, card: $this->card())));
    }

    /**
     * A provider is not asked for Article 26(2).
     *
     * This is the case that would have been silently satisfied by the old gate
     * and is the point of the split: the oversight store is empty throughout,
     * and the model passes, because assigning oversight is a duty Article 26 puts
     * on the deployer.
     */
    #[Test]
    public function aProviderIsNotAskedForTheDeployersOversightAssignment(): void
    {
        $this->assessments->assess('model-1');
        $this->monitoringHooks->add($this->hook());

        self::assertFalse($this->oversight->isOverseen('model-1'));
        self::assertTrue($this->gate->evaluate($this->model(AiModelRiskLevel::High, card: $this->card())));
    }

    // --- The deployer limb: Article 26(2) -------------------------------------

    /**
     * The defect this whole split exists to fix.
     *
     * A deployer of a bought-in high-risk model holds no Annex IV documentation
     * and draws up no risk management system — those are the provider's. The old
     * gate refused this deployment for failing all three, which is a refusal for
     * duties it does not owe.
     */
    #[Test]
    public function aDeployerIsNotAskedForTheProvidersPreMarketArtefacts(): void
    {
        $gate = $this->gateFor(AiActorRole::Deployer);
        $this->assignOversight();

        // No assessment, no model card, no monitoring hook — and it passes,
        // because Article 26 asks a deployer for none of them.
        self::assertTrue($gate->evaluate($this->model(AiModelRiskLevel::High)));
    }

    #[Test]
    public function refusesADeployersHighRiskModelWithNobodyAssignedToOverseeIt(): void
    {
        $gate = $this->gateFor(AiActorRole::Deployer);

        self::assertFalse($gate->evaluate($this->model(AiModelRiskLevel::High)));

        $reason = $gate->failureReason();
        self::assertStringContainsString('Article 26(2)', $reason);
        self::assertStringContainsString('Article 14(4)', $reason);
    }

    #[Test]
    public function theDeployerRefusalNamesNoProviderArticle(): void
    {
        $gate = $this->gateFor(AiActorRole::Deployer);
        (void) $gate->evaluate($this->model(AiModelRiskLevel::High));

        $reason = $gate->failureReason();
        self::assertStringNotContainsString('Article 9,', $reason);
        self::assertStringNotContainsString('Annex IV', $reason);
        self::assertStringNotContainsString('Article 72', $reason);
    }

    #[Test]
    public function withdrawingTheLastOverseerClosesTheGateAgain(): void
    {
        $gate = $this->gateFor(AiActorRole::Deployer);
        $this->assignOversight();

        self::assertTrue($gate->evaluate($this->model(AiModelRiskLevel::High)));

        // Article 26(2) is a continuing duty, so a deployment whose only named
        // overseer leaves is no longer overseen and must not keep passing.
        $this->oversight->withdraw('model-1', 'clinician-7');

        self::assertFalse($gate->evaluate($this->model(AiModelRiskLevel::High)));
    }

    // --- Both at once: Article 25 -------------------------------------------

    #[Test]
    public function anEntityThatIsBothMustSatisfyBothLimbs(): void
    {
        $gate = $this->gateFor(AiActorRole::ProviderAndDeployer);
        $this->assessments->assess('model-1');
        $this->monitoringHooks->add($this->hook());

        // Everything a provider owes, and no oversight: still refused.
        self::assertFalse($gate->evaluate($this->model(AiModelRiskLevel::High, card: $this->card())));
        self::assertStringContainsString('Article 26(2)', $gate->failureReason());

        $this->assignOversight();

        self::assertTrue($gate->evaluate($this->model(AiModelRiskLevel::High, card: $this->card())));
    }

    // --- The role itself ------------------------------------------------------

    #[Test]
    public function refusesAHighRiskModelWhoseRoleNobodyDeclared(): void
    {
        $gate = $this->gateFor(null);
        $this->assessments->assess('model-1');
        $this->monitoringHooks->add($this->hook());
        $this->assignOversight();

        // Every artefact either limb could ask for is on record, and it is still
        // refused: which obligations apply is not something the gate may guess.
        self::assertFalse($gate->evaluate($this->model(AiModelRiskLevel::High, card: $this->card())));

        $reason = $gate->failureReason();
        self::assertStringContainsString('Article 3(3)', $reason);
        self::assertStringContainsString('Article 3(4)', $reason);
        self::assertStringContainsString('declared neither role', $reason);
    }

    #[Test]
    public function theSystemsOwnRoleOverridesTheDeploymentsRole(): void
    {
        // The deployment says it is a provider; this one system says it is only a
        // deployer of it. An organisation that builds one model and buys another
        // has exactly this shape.
        $gate = $this->gateFor(AiActorRole::Provider);
        $this->assignOversight();

        $boughtIn = $this->model(AiModelRiskLevel::High)->withActorRole(AiActorRole::Deployer);

        self::assertTrue($gate->evaluate($boughtIn));
    }

    #[Test]
    public function aDeploymentWithNoRoleCanStillDeclareOnePerSystem(): void
    {
        $gate = $this->gateFor(null);
        $this->assessments->assess('model-1');
        $this->monitoringHooks->add($this->hook());

        $own = $this->model(AiModelRiskLevel::High, card: $this->card())
            ->withActorRole(AiActorRole::Provider);

        self::assertTrue($gate->evaluate($own));
    }

    // --- Everything else ------------------------------------------------------

    #[Test]
    #[DataProvider('tiersWithoutPreMarketObligations')]
    public function leavesEveryOtherTierAlone(AiModelRiskLevel $riskLevel): void
    {
        // With no role declared anywhere, so that the tier check is shown to run
        // before the role check: a minimal-risk model owes nothing under either
        // set and must not be blocked for failing to say which set it is in.
        self::assertTrue($this->gateFor(null)->evaluate($this->model($riskLevel)));
    }

    /**
     * @return iterable<string, array{AiModelRiskLevel}>
     */
    public static function tiersWithoutPreMarketObligations(): iterable
    {
        yield 'minimal' => [AiModelRiskLevel::Minimal];
        yield 'limited' => [AiModelRiskLevel::Limited];
        yield 'unacceptable, which the prohibition gate refuses instead' => [AiModelRiskLevel::Unacceptable];
    }

    #[Test]
    public function reportsTheUndeclaredRoleBeforeAnyEvaluation(): void
    {
        $reason = $this->gate->failureReason();

        // Before any evaluation the honest answer is what it requires of a
        // deployment that has declared nothing: both sets, named, and the reason
        // neither can be enforced.
        self::assertStringContainsString('Article 9', $reason);
        self::assertStringContainsString('Article 11', $reason);
        self::assertStringContainsString('Article 72', $reason);
        self::assertStringContainsString('Article 26(2)', $reason);
    }

    #[Test]
    public function isNamedSoTheAuditRecordIdentifiesIt(): void
    {
        self::assertSame('high_risk_obligations', $this->gate->name());
    }

    /**
     * Article 72(3) is answered by whatever registry the deployment wired, not
     * only by the one this extension ships.
     *
     * The gate used to name {@see MonitoringHookRegistry}, which is `final`: a
     * deployment holding its monitoring hooks anywhere else — a registry that
     * refuses registrations after boot, one that counts them for the Article 72
     * record — could not be asked the question at all. The substitute below is
     * not that class and answers it.
     */
    #[Test]
    public function readsThePostMarketAnswerFromAnyRegistryTheDeploymentWired(): void
    {
        $this->assessments->assess('model-1');

        $empty = new SubstituteHookRegistry(hooks: []);
        $stocked = new SubstituteHookRegistry(hooks: [$this->hook()]);

        $refusing = new HighRiskObligationsGate(
            $this->assessments,
            $empty,
            $this->oversight,
            AiActorRole::Provider,
        );
        $passing = new HighRiskObligationsGate(
            $this->assessments,
            $stocked,
            $this->oversight,
            AiActorRole::Provider,
        );

        self::assertFalse($refusing->evaluate($this->model(AiModelRiskLevel::High, card: $this->card())));
        self::assertStringContainsString('Article 72', $refusing->failureReason());

        self::assertTrue($passing->evaluate($this->model(AiModelRiskLevel::High, card: $this->card())));
    }

    // --- Fixtures -------------------------------------------------------------

    private function gateFor(?AiActorRole $role): HighRiskObligationsGate
    {
        return new HighRiskObligationsGate(
            $this->assessments,
            $this->monitoringHooks,
            $this->oversight,
            $role,
        );
    }

    private function assignOversight(): void
    {
        $this->oversight->assign(new OversightAssignment(
            modelId: 'model-1',
            overseerId: 'clinician-7',
            competenceBasis: 'Registered clinician, completed the model-specific training in 2026.',
            authorityBasis: 'Named in the clinical safety policy as able to suspend the system.',
            capabilities: OversightCapability::exercisable(),
            assignedAt: new DateTimeImmutable('2026-01-01'),
        ));
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
            id: 'model-1',
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

/**
 * A hook collection that is not the one this extension ships.
 *
 * Deliberately a different shape: it is handed its hooks whole and refuses to
 * grow, which is a registry a deployment might actually want and which
 * `MonitoringHookRegistry` cannot be configured into.
 */
final class SubstituteHookRegistry implements MonitoringHookRegistryInterface
{
    /**
     * @param list<MonitoringHookInterface> $hooks
     */
    public function __construct(private readonly array $hooks) {}

    public function add(MonitoringHookInterface $hook): void
    {
        throw new LogicException('this registry is fixed at construction: ' . $hook->name());
    }

    /**
     * @return list<MonitoringHookInterface>
     */
    public function all(): array
    {
        return $this->hooks;
    }

    public function isEmpty(): bool
    {
        return $this->hooks === [];
    }
}
