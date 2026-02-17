<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Enum\AiAuditEvent;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Enum\ImpactCategory;
use Pulsar\Extension\AiGovernance\Enum\ImpactSeverity;

#[CoversClass(AiAuditEvent::class)]
#[CoversClass(AiModelRiskLevel::class)]
#[CoversClass(AiModelStatus::class)]
#[CoversClass(ImpactCategory::class)]
#[CoversClass(ImpactSeverity::class)]
final class EnumTest extends TestCase
{
    /**
     * @return iterable<string, array{AiAuditEvent, string}>
     */
    public static function auditEventProvider(): iterable
    {
        yield 'model_invoked' => [AiAuditEvent::ModelInvoked, 'ai.model_invoked'];
        yield 'decision_made' => [AiAuditEvent::DecisionMade, 'ai.decision_made'];
        yield 'human_override' => [AiAuditEvent::HumanOverride, 'ai.human_override'];
        yield 'bias_detected' => [AiAuditEvent::BiasDetected, 'ai.bias_detected'];
        yield 'model_deployed' => [AiAuditEvent::ModelDeployed, 'ai.model_deployed'];
        yield 'model_retired' => [AiAuditEvent::ModelRetired, 'ai.model_retired'];
        yield 'impact_assessed' => [AiAuditEvent::ImpactAssessed, 'ai.impact_assessed'];
        yield 'data_provenance_recorded' => [AiAuditEvent::DataProvenanceRecorded, 'ai.data_provenance_recorded'];
        yield 'transparency_report' => [AiAuditEvent::TransparencyReportGenerated, 'ai.transparency_report_generated'];
        yield 'gate_passed' => [AiAuditEvent::DeploymentGatePassed, 'ai.deployment_gate_passed'];
        yield 'gate_failed' => [AiAuditEvent::DeploymentGateFailed, 'ai.deployment_gate_failed'];
    }

    #[Test]
    #[DataProvider('auditEventProvider')]
    public function aiAuditEventValues(AiAuditEvent $event, string $expected): void
    {
        self::assertSame($expected, $event->value);
    }

    #[Test]
    public function aiAuditEventHas11Cases(): void
    {
        self::assertCount(11, AiAuditEvent::cases());
    }

    #[Test]
    public function allAuditEventsHaveAiPrefix(): void
    {
        foreach (AiAuditEvent::cases() as $event) {
            self::assertStringStartsWith('ai.', $event->value, "Event {$event->name} must start with 'ai.' prefix");
        }
    }

    /**
     * @return iterable<string, array{AiModelRiskLevel, string}>
     */
    public static function riskLevelProvider(): iterable
    {
        yield 'minimal' => [AiModelRiskLevel::Minimal, 'minimal'];
        yield 'limited' => [AiModelRiskLevel::Limited, 'limited'];
        yield 'high' => [AiModelRiskLevel::High, 'high'];
        yield 'unacceptable' => [AiModelRiskLevel::Unacceptable, 'unacceptable'];
    }

    #[Test]
    #[DataProvider('riskLevelProvider')]
    public function aiModelRiskLevelValues(AiModelRiskLevel $level, string $expected): void
    {
        self::assertSame($expected, $level->value);
    }

    #[Test]
    public function aiModelRiskLevelHas4Cases(): void
    {
        self::assertCount(4, AiModelRiskLevel::cases());
    }

    /**
     * @return iterable<string, array{AiModelStatus, string}>
     */
    public static function statusProvider(): iterable
    {
        yield 'development' => [AiModelStatus::Development, 'development'];
        yield 'testing' => [AiModelStatus::Testing, 'testing'];
        yield 'staging' => [AiModelStatus::Staging, 'staging'];
        yield 'production' => [AiModelStatus::Production, 'production'];
        yield 'deprecated' => [AiModelStatus::Deprecated, 'deprecated'];
        yield 'retired' => [AiModelStatus::Retired, 'retired'];
    }

    #[Test]
    #[DataProvider('statusProvider')]
    public function aiModelStatusValues(AiModelStatus $status, string $expected): void
    {
        self::assertSame($expected, $status->value);
    }

    #[Test]
    public function aiModelStatusHas6Cases(): void
    {
        self::assertCount(6, AiModelStatus::cases());
    }

    /**
     * @return iterable<string, array{ImpactCategory, string}>
     */
    public static function categoryProvider(): iterable
    {
        yield 'fairness' => [ImpactCategory::Fairness, 'fairness'];
        yield 'transparency' => [ImpactCategory::Transparency, 'transparency'];
        yield 'accountability' => [ImpactCategory::Accountability, 'accountability'];
        yield 'privacy' => [ImpactCategory::Privacy, 'privacy'];
        yield 'safety' => [ImpactCategory::Safety, 'safety'];
        yield 'security' => [ImpactCategory::Security, 'security'];
    }

    #[Test]
    #[DataProvider('categoryProvider')]
    public function impactCategoryValues(ImpactCategory $category, string $expected): void
    {
        self::assertSame($expected, $category->value);
    }

    #[Test]
    public function impactCategoryHas6Cases(): void
    {
        self::assertCount(6, ImpactCategory::cases());
    }

    /**
     * @return iterable<string, array{ImpactSeverity, string}>
     */
    public static function severityProvider(): iterable
    {
        yield 'low' => [ImpactSeverity::Low, 'low'];
        yield 'medium' => [ImpactSeverity::Medium, 'medium'];
        yield 'high' => [ImpactSeverity::High, 'high'];
        yield 'critical' => [ImpactSeverity::Critical, 'critical'];
    }

    #[Test]
    #[DataProvider('severityProvider')]
    public function impactSeverityValues(ImpactSeverity $severity, string $expected): void
    {
        self::assertSame($expected, $severity->value);
    }

    #[Test]
    public function impactSeverityHas4Cases(): void
    {
        self::assertCount(4, ImpactSeverity::cases());
    }
}
