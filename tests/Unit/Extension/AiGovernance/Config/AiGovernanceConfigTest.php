<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\AiGovernance\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Config\AiGovernanceConfig;

#[CoversClass(AiGovernanceConfig::class)]
final class AiGovernanceConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreSecureByDefault(): void
    {
        $config = new AiGovernanceConfig();

        self::assertTrue($config->auditInvocations);
        self::assertTrue($config->requireImpactAssessment);
        self::assertFalse($config->requireModelCard);
        self::assertTrue($config->requireConsentForTrainingData);
        self::assertSame('memory', $config->registryStore);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = AiGovernanceConfig::fromArray([
            'audit_invocations' => false,
            'require_impact_assessment' => false,
            'require_model_card' => true,
            'require_consent_for_training_data' => false,
            'registry_store' => 'database',
            'data_governance_store' => 'redis',
            'explainability_store' => 'custom',
        ]);

        self::assertFalse($config->auditInvocations);
        self::assertFalse($config->requireImpactAssessment);
        self::assertTrue($config->requireModelCard);
        self::assertFalse($config->requireConsentForTrainingData);
        self::assertSame('database', $config->registryStore);
        self::assertSame('redis', $config->dataGovernanceStore);
        self::assertSame('custom', $config->explainabilityStore);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = AiGovernanceConfig::fromArray([]);

        self::assertTrue($config->auditInvocations);
        self::assertTrue($config->requireImpactAssessment);
        self::assertSame('memory', $config->registryStore);
    }

    #[Test]
    public function fromArrayCoercesBooleans(): void
    {
        $config = AiGovernanceConfig::fromArray([
            'audit_invocations' => 1,
            'require_impact_assessment' => 0,
        ]);

        self::assertTrue($config->auditInvocations);
        self::assertFalse($config->requireImpactAssessment);
    }

    #[Test]
    public function fromArrayIgnoresNonStringStoreValues(): void
    {
        $config = AiGovernanceConfig::fromArray([
            'registry_store' => 42,
            'data_governance_store' => null,
            'explainability_store' => ['invalid'],
        ]);

        self::assertSame('memory', $config->registryStore);
        self::assertSame('memory', $config->dataGovernanceStore);
        self::assertSame('memory', $config->explainabilityStore);
    }
}
