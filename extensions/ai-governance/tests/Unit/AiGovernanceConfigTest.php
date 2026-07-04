<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Config\AiGovernanceConfig;

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
        self::assertSame('memory', $config->registryStore);
        self::assertSame('memory', $config->dataGovernanceStore);
        self::assertSame('memory', $config->explainabilityStore);
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
        ]);

        self::assertFalse($config->auditInvocations);
        self::assertFalse($config->requireImpactAssessment);
        self::assertTrue($config->requireModelCard);
        self::assertFalse($config->requireConsentForTrainingData);
        self::assertSame(5.5, $config->impactRiskThreshold);
        self::assertSame('App\\Store\\DatabaseModelRegistry', $config->registryStore);
        self::assertSame('App\\Store\\DatabaseDataGovernance', $config->dataGovernanceStore);
        self::assertSame('App\\Store\\DatabaseExplainability', $config->explainabilityStore);
    }

    public function testFromArrayWithPartialOverrides(): void
    {
        $config = AiGovernanceConfig::fromArray([
            'require_model_card' => true,
        ]);

        self::assertTrue($config->auditInvocations);
        self::assertTrue($config->requireModelCard);
        self::assertSame('memory', $config->registryStore);
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
}
