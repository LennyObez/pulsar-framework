<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\ImpactFinding;
use Pulsar\Extension\AiGovernance\Dto\ModelCard;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Enum\ImpactCategory;
use Pulsar\Extension\AiGovernance\Enum\ImpactSeverity;
use Pulsar\Extension\AiGovernance\Internal\Monitoring\GovernanceConformityHook;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryImpactAssessmentStore;

/**
 * The first `MonitoringHookInterface` implementation in the tree, and what it
 * measures.
 *
 * Until rc.12 the interface had ZERO implementations anywhere in this
 * repository. ISO 42001 Clause 9.1, Annex A.7 and Clause 10.1 all cited it, so
 * none of the three could be satisfied by any deployment, and the Article 72(3)
 * limb of the high-risk deployment gate could never pass either.
 *
 * WHAT IS ASSERTED AND WHAT IS DELIBERATELY NOT. This hook re-reads, on a model
 * already in service, the obligations that model was admitted under: the Article
 * 5 classification, the Article 9 assessment, the Article 11 model card, and the
 * assessed risk score against the deployment's threshold. Each of those can
 * change after the deployment gates have run and nothing else re-reads them,
 * which is precisely why Article 72 calls the duty post-market monitoring.
 *
 * It does NOT measure model performance, drift or bias emergence. Those are
 * properties of a model's outputs against ground truth, the framework sees
 * neither, and a hook that scored them from metadata would be manufacturing the
 * finding an assessor is supposed to be shown.
 */
#[CoversClass(GovernanceConformityHook::class)]
final class GovernanceConformityHookTest extends TestCase
{
    private InMemoryImpactAssessmentStore $assessments;

    protected function setUp(): void
    {
        $this->assessments = new InMemoryImpactAssessmentStore();
    }

    #[Test]
    public function itNamesItselfSoARetainedRecordCanCiteTheMethod(): void
    {
        self::assertSame('governance_conformity', $this->hook()->name());
        self::assertSame(GovernanceConformityHook::NAME, $this->hook()->name());
    }

    #[Test]
    public function aMinimalRiskModelWithNoObligationsIsHealthy(): void
    {
        $result = $this->hook()->check($this->model(AiModelRiskLevel::Minimal));

        self::assertTrue($result->healthy);
        self::assertSame('governance_conformity', $result->hookName);
        self::assertStringContainsString('still satisfies', $result->message);
        self::assertSame(0, $result->metrics['breaches']);
        self::assertFalse($result->metrics['carries_high_risk_obligations']);
    }

    #[Test]
    public function aHighRiskModelInServiceWithoutAnAssessmentIsUnhealthy(): void
    {
        $result = $this->hook()->check($this->model(AiModelRiskLevel::High, card: $this->card()));

        self::assertFalse($result->healthy);
        self::assertStringContainsString('Article 9', $result->message);
        self::assertFalse($result->metrics['has_impact_assessment']);
        self::assertSame(1, $result->metrics['breaches']);
    }

    #[Test]
    public function aHighRiskModelInServiceWithoutAModelCardIsUnhealthy(): void
    {
        $this->assessments->assess('m1');

        $result = $this->hook()->check($this->model(AiModelRiskLevel::High));

        self::assertFalse($result->healthy);
        self::assertStringContainsString('Article 11', $result->message);
        self::assertFalse($result->metrics['has_model_card']);
    }

    #[Test]
    public function aHighRiskModelCarryingBothObligationsIsHealthy(): void
    {
        $this->assessments->assess('m1');

        $result = $this->hook()->check($this->model(AiModelRiskLevel::High, card: $this->card()));

        self::assertTrue($result->healthy);
        self::assertTrue($result->metrics['has_impact_assessment']);
        self::assertTrue($result->metrics['has_model_card']);
        self::assertTrue($result->metrics['carries_high_risk_obligations']);
    }

    /**
     * The drift the gates cannot see. A finding added to a model already in
     * production raises its risk score, and nothing re-runs the deployment gate;
     * this hook is what notices.
     */
    #[Test]
    public function aFindingAddedAfterDeploymentPushesTheModelOverTheThreshold(): void
    {
        $this->assessments->assess('m1');
        $model = $this->model(AiModelRiskLevel::High, card: $this->card());

        self::assertTrue($this->hook()->check($model)->healthy, 'admitted, and conforming at the gate');

        $this->assessments->addFinding('m1', $this->finding(ImpactSeverity::Critical));

        $result = $this->hook()->check($model);

        self::assertFalse($result->healthy, 'and no longer conforming once the assessment moved');
        self::assertStringContainsString('impact risk has reached', $result->message);
        self::assertSame(10.0, $result->metrics['impact_risk_score']);
        self::assertSame(7.0, $result->metrics['impact_risk_threshold']);
        self::assertSame(1, $result->metrics['impact_findings']);
    }

    #[Test]
    public function aFindingBelowTheThresholdIsRecordedAndDoesNotBreach(): void
    {
        $this->assessments->assess('m1');
        $this->assessments->addFinding('m1', $this->finding(ImpactSeverity::Medium));

        $result = $this->hook()->check($this->model(AiModelRiskLevel::High, card: $this->card()));

        self::assertTrue($result->healthy);
        self::assertSame(3.0, $result->metrics['impact_risk_score']);
    }

    /**
     * A model reclassified as an Article 5 prohibited practice is a breach
     * whatever else it carries, and the message names the Article rather than a
     * house rule.
     */
    #[Test]
    public function aProhibitedPracticeIsAlwaysABreach(): void
    {
        $this->assessments->assess('m1');

        $result = $this->hook()->check($this->model(AiModelRiskLevel::Unacceptable, card: $this->card()));

        self::assertFalse($result->healthy);
        self::assertStringContainsString('Article 5', $result->message);
        self::assertSame('unacceptable', $result->metrics['risk_level']);
    }

    #[Test]
    public function theMetricsRecordWhetherTheModelIsActuallyInService(): void
    {
        $serving = $this->hook()->check($this->model(AiModelRiskLevel::Minimal));
        $shelved = $this->hook()->check(
            $this->model(AiModelRiskLevel::Minimal, status: AiModelStatus::Development),
        );

        self::assertTrue($serving->metrics['in_service']);
        self::assertFalse($shelved->metrics['in_service']);
        self::assertSame('development', $shelved->metrics['status']);
    }

    /**
     * The context argument is accepted and not read, and that is deliberate:
     * every input this check reads is a fact the governance record holds, and a
     * check taking its inputs from the caller would be grading the caller.
     */
    #[Test]
    public function theResultDoesNotChangeWithWhateverContextTheCallerPasses(): void
    {
        $model = $this->model(AiModelRiskLevel::Minimal);

        $plain = $this->hook()->check($model);
        $flattered = $this->hook()->check($model, ['healthy' => true, 'breaches' => 0, 'accuracy' => 1.0]);

        self::assertSame($plain->healthy, $flattered->healthy);
        self::assertSame($plain->metrics, $flattered->metrics);
    }

    private function hook(float $threshold = 7.0): GovernanceConformityHook
    {
        return new GovernanceConformityHook($this->assessments, $threshold);
    }

    private function model(
        AiModelRiskLevel $risk,
        ?ModelCard $card = null,
        AiModelStatus $status = AiModelStatus::Production,
    ): AiModel {
        return new AiModel(
            id: 'm1',
            name: 'Applicant ranker',
            version: '2.0.0',
            provider: 'acme',
            type: 'classifier',
            riskLevel: $risk,
            status: $status,
            registeredAt: new DateTimeImmutable('@1785628800')->setTimezone(new DateTimeZone('UTC')),
            card: $card,
        );
    }

    private function card(): ModelCard
    {
        return new ModelCard(
            description: 'Ranks job applications',
            intendedUse: 'Shortlisting, with mandatory human review',
        );
    }

    private function finding(ImpactSeverity $severity): ImpactFinding
    {
        return new ImpactFinding(
            id: 'f1',
            category: ImpactCategory::Fairness,
            severity: $severity,
            title: 'Age skew observed in production traffic',
            description: 'Applicants over 60 are shortlisted at a materially lower rate.',
            recommendation: 'Suspend automated shortlisting for that cohort pending review.',
        );
    }
}
