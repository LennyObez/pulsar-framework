<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Config\AiGovernanceConfig;
use Pulsar\Extension\AiGovernance\Enum\AiActorRole;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;

#[CoversClass(AiGovernanceConfig::class)]
final class AiGovernanceConfigTest extends TestCase
{
    public function testFromArrayWithDefaults(): void
    {
        $config = AiGovernanceConfig::fromArray([]);

        self::assertTrue($config->auditInvocations);
        self::assertTrue($config->requireImpactAssessment);
        self::assertFalse($config->requireModelCard);
        self::assertTrue($config->requireConsentForTrainingData);
        self::assertSame(7.0, $config->impactRiskThreshold);

        // Every store key defaulted to 'memory' until rc.12, so a deployment that
        // enabled this extension and configured nothing kept its AI model
        // inventory, impact assessments, data provenance, explanations and
        // monitoring results in one worker's memory — thirteen ISO 42001 controls
        // resting on a record that was gone at the next restart. This assertion is
        // what stops that default coming back by accident.
        self::assertSame('database', $config->registryStore);
        self::assertSame('database', $config->impactAssessmentStore);
        self::assertSame('database', $config->dataGovernanceStore);
        self::assertSame('database', $config->explainabilityStore);
        self::assertSame('database', $config->monitoringRecordStore);
    }

    public function testFromArrayWithCustomValues(): void
    {
        $config = AiGovernanceConfig::fromArray([
            'audit_invocations' => false,
            'require_impact_assessment' => false,
            'require_model_card' => true,
            'require_consent_for_training_data' => false,
            'impact_risk_threshold' => 5.5,
            'registry_store' => 'App\\Store\\DatabaseModelRegistry',
            'data_governance_store' => 'App\\Store\\DatabaseDataGovernance',
            'explainability_store' => 'App\\Store\\DatabaseExplainability',
            'impact_assessment_store' => 'App\\Store\\DatabaseImpactAssessments',
            'monitoring_record_store' => 'App\\Store\\DatabaseMonitoringRecords',
        ]);

        self::assertFalse($config->auditInvocations);
        self::assertFalse($config->requireImpactAssessment);
        self::assertTrue($config->requireModelCard);
        self::assertFalse($config->requireConsentForTrainingData);
        self::assertSame(5.5, $config->impactRiskThreshold);
        self::assertSame('App\\Store\\DatabaseModelRegistry', $config->registryStore);
        self::assertSame('App\\Store\\DatabaseDataGovernance', $config->dataGovernanceStore);
        self::assertSame('App\\Store\\DatabaseExplainability', $config->explainabilityStore);
        self::assertSame('App\\Store\\DatabaseImpactAssessments', $config->impactAssessmentStore);
        self::assertSame('App\\Store\\DatabaseMonitoringRecords', $config->monitoringRecordStore);
    }

    public function testFromArrayWithPartialOverrides(): void
    {
        $config = AiGovernanceConfig::fromArray([
            'require_model_card' => true,
        ]);

        self::assertTrue($config->auditInvocations);
        self::assertTrue($config->requireModelCard);
        self::assertSame('database', $config->registryStore);
    }

    public function testConstructorDirectly(): void
    {
        $config = new AiGovernanceConfig(
            auditInvocations: false,
            requireImpactAssessment: false,
            requireModelCard: true,
        );

        self::assertFalse($config->auditInvocations);
        self::assertFalse($config->requireImpactAssessment);
        self::assertTrue($config->requireModelCard);
    }

    /**
     * The EU AI Act role ships UNDECLARED, and undeclared is a real state.
     *
     * It decides which obligations apply — Articles 9, 11 and 72(3) for a
     * provider, Article 26 for a deployer — so a default would be the framework
     * answering a legal question on the operator's behalf. Absent means the
     * high-risk gate refuses and names both sets.
     */
    public function testActorRoleIsUndeclaredByDefault(): void
    {
        self::assertNull(AiGovernanceConfig::fromArray([])->actorRole);
    }

    public function testActorRoleIsReadFromConfiguration(): void
    {
        self::assertSame(
            AiActorRole::Deployer,
            AiGovernanceConfig::fromArray(['actor_role' => 'deployer'])->actorRole,
        );
        self::assertSame(
            AiActorRole::ProviderAndDeployer,
            AiGovernanceConfig::fromArray(['actor_role' => 'provider_and_deployer'])->actorRole,
        );
    }

    public function testABlankActorRoleIsUndeclaredRatherThanInvalid(): void
    {
        self::assertNull(AiGovernanceConfig::fromArray(['actor_role' => ''])->actorRole);
    }

    /**
     * A misspelt role must not become an exemption.
     *
     * Degrading an unrecognised value to "unstated" would leave a deployment that
     * meant to declare itself a provider being treated as having declared
     * nothing — and reading the refusal as a bug in the gate rather than a typo
     * in the file.
     */
    public function testAnActorRoleTheActDoesNotDefineFailsLoudly(): void
    {
        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/Article 3\(3\)/');

        (void) AiGovernanceConfig::fromArray(['actor_role' => 'operator']);
    }

    public function testTheOversightStoreDefaultsToTheDurableOne(): void
    {
        // The intervention register is the only artefact in the Article 14
        // subsystem that is evidence rather than intent.
        self::assertSame('database', AiGovernanceConfig::fromArray([])->oversightStore);
    }

    public function testTheTransparencyStoreDefaultsToTheDurableOne(): void
    {
        // Article 50 has applied since 2 August 2026 and was bound to an
        // in-memory store unconditionally until rc.12 — the one obligation in
        // force was the only one whose register did not survive a restart.
        self::assertSame('database', AiGovernanceConfig::fromArray([])->transparencyStore);
    }
}
