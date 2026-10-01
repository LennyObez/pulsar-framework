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
    /**
     * EVERY STORE DEFAULTS TO THE DURABLE ONE, and that is the assertion this case
     * exists for since rc.12. The default was `memory` for every key, so a
     * deployment that enabled this extension and configured nothing got a model
     * inventory, impact assessments, provenance and explanations that lived in one
     * worker's memory -- thirteen ISO 42001 controls resting on a record that was
     * gone at the next restart. Asserted key by key rather than on the registry
     * alone: they moved together, and one left behind on `memory` is the shape
     * nobody would notice.
     */
    #[Test]
    public function defaultsAreSecureByDefault(): void
    {
        $config = new AiGovernanceConfig();

        self::assertTrue($config->auditInvocations);
        self::assertTrue($config->requireImpactAssessment);
        self::assertFalse($config->requireModelCard);
        self::assertTrue($config->requireConsentForTrainingData);
        self::assertSame(AiGovernanceConfig::DATABASE, $config->registryStore);
        self::assertSame(AiGovernanceConfig::DATABASE, $config->impactAssessmentStore);
        self::assertSame(AiGovernanceConfig::DATABASE, $config->dataGovernanceStore);
        self::assertSame(AiGovernanceConfig::DATABASE, $config->explainabilityStore);
        self::assertSame(AiGovernanceConfig::DATABASE, $config->monitoringRecordStore);
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
        self::assertSame(AiGovernanceConfig::DATABASE, $config->registryStore);
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

        // A value of the wrong type falls back to the DEFAULT, which is the durable
        // store. Falling back to `memory` would turn a typo in one config key into
        // a store that silently forgets, which is the failure the default moved to
        // stop.
        self::assertSame(AiGovernanceConfig::DATABASE, $config->registryStore);
        self::assertSame(AiGovernanceConfig::DATABASE, $config->dataGovernanceStore);
        self::assertSame(AiGovernanceConfig::DATABASE, $config->explainabilityStore);
    }
}
