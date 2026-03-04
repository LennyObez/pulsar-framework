<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Iso42001;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Iso42001\AiBiasDetected;
use Pulsar\Security\Compliance\Event\Iso42001\AiHumanOverride;
use Pulsar\Security\Compliance\Event\Iso42001\AiImpactAssessed;
use Pulsar\Security\Compliance\Event\Iso42001\AiModelDeployed;

#[CoversClass(AiBiasDetected::class)]
#[CoversClass(AiHumanOverride::class)]
#[CoversClass(AiImpactAssessed::class)]
#[CoversClass(AiModelDeployed::class)]
final class Iso42001EventTest extends TestCase
{
    // ── AiBiasDetected ──────────────────────────────────────────────

    #[Test]
    public function aiBiasDetectedStoresProperties(): void
    {
        $now = new DateTimeImmutable('2026-03-15T10:00:00+00:00');
        $event = new AiBiasDetected(
            eventId: 'evt-1',
            occurredAt: $now,
            correlationId: 'corr-1',
            nonce: 'nonce-1',
            modelId: 'model-abc',
            biasType: 'gender',
            affectedGroup: 'female',
            description: 'Underrepresentation in hiring recommendations',
            detectedBy: 'fairness-scanner',
        );

        self::assertSame('evt-1', $event->eventId);
        self::assertSame($now, $event->occurredAt);
        self::assertSame('corr-1', $event->correlationId);
        self::assertSame('nonce-1', $event->nonce);
        self::assertSame('model-abc', $event->modelId);
        self::assertSame('gender', $event->biasType);
        self::assertSame('female', $event->affectedGroup);
        self::assertSame('Underrepresentation in hiring recommendations', $event->description);
        self::assertSame('fairness-scanner', $event->detectedBy);
    }

    #[Test]
    public function aiBiasDetectedRegulationAndEventType(): void
    {
        $event = $this->createBiasEvent();

        self::assertSame('iso42001', $event->regulation());
        self::assertSame('ai_bias_detected', $event->eventType());
    }

    #[Test]
    public function aiBiasDetectedRoundTrip(): void
    {
        $original = $this->createBiasEvent();
        $array = $original->toArray();
        $restored = AiBiasDetected::fromArray($array);

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->modelId, $restored->modelId);
        self::assertSame($original->biasType, $restored->biasType);
        self::assertSame($original->affectedGroup, $restored->affectedGroup);
        self::assertSame($original->description, $restored->description);
        self::assertSame($original->detectedBy, $restored->detectedBy);
        self::assertSame(1, $array['schema_version']);
        self::assertSame('iso42001', $array['regulation']);
        self::assertSame('ai_bias_detected', $array['event_type']);
    }

    #[Test]
    public function aiBiasDetectedFromArrayDefaultsOnMissingKeys(): void
    {
        $event = AiBiasDetected::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->modelId);
        self::assertSame('', $event->biasType);
        self::assertSame('', $event->affectedGroup);
        self::assertSame('', $event->description);
        self::assertSame('', $event->detectedBy);
    }

    // ── AiHumanOverride ─────────────────────────────────────────────

    #[Test]
    public function aiHumanOverrideStoresProperties(): void
    {
        $now = new DateTimeImmutable('2026-03-15T11:00:00+00:00');
        $event = new AiHumanOverride(
            eventId: 'evt-2',
            occurredAt: $now,
            correlationId: 'corr-2',
            nonce: 'nonce-2',
            modelId: 'model-xyz',
            decisionId: 'dec-99',
            overriddenBy: 'admin@example.com',
            reason: 'False positive flagged by model',
        );

        self::assertSame('model-xyz', $event->modelId);
        self::assertSame('dec-99', $event->decisionId);
        self::assertSame('admin@example.com', $event->overriddenBy);
        self::assertSame('False positive flagged by model', $event->reason);
    }

    #[Test]
    public function aiHumanOverrideRegulationAndEventType(): void
    {
        $event = $this->createOverrideEvent();

        self::assertSame('iso42001', $event->regulation());
        self::assertSame('ai_human_override', $event->eventType());
    }

    #[Test]
    public function aiHumanOverrideRoundTrip(): void
    {
        $original = $this->createOverrideEvent();
        $array = $original->toArray();
        $restored = AiHumanOverride::fromArray($array);

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->modelId, $restored->modelId);
        self::assertSame($original->decisionId, $restored->decisionId);
        self::assertSame($original->overriddenBy, $restored->overriddenBy);
        self::assertSame($original->reason, $restored->reason);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function aiHumanOverrideFromArrayDefaultsOnMissingKeys(): void
    {
        $event = AiHumanOverride::fromArray([]);

        self::assertSame('', $event->modelId);
        self::assertSame('', $event->decisionId);
        self::assertSame('', $event->overriddenBy);
        self::assertSame('', $event->reason);
    }

    // ── AiImpactAssessed ────────────────────────────────────────────

    #[Test]
    public function aiImpactAssessedStoresProperties(): void
    {
        $now = new DateTimeImmutable('2026-03-15T12:00:00+00:00');
        $event = new AiImpactAssessed(
            eventId: 'evt-3',
            occurredAt: $now,
            correlationId: 'corr-3',
            nonce: 'nonce-3',
            modelId: 'model-impact',
            riskScore: 0.75,
            findingsCount: 3,
            assessedBy: 'risk-team',
        );

        self::assertSame('model-impact', $event->modelId);
        self::assertSame(0.75, $event->riskScore);
        self::assertSame(3, $event->findingsCount);
        self::assertSame('risk-team', $event->assessedBy);
    }

    #[Test]
    public function aiImpactAssessedRegulationAndEventType(): void
    {
        $event = $this->createImpactEvent();

        self::assertSame('iso42001', $event->regulation());
        self::assertSame('ai_impact_assessed', $event->eventType());
    }

    #[Test]
    public function aiImpactAssessedRoundTrip(): void
    {
        $original = $this->createImpactEvent();
        $array = $original->toArray();
        $restored = AiImpactAssessed::fromArray($array);

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->modelId, $restored->modelId);
        self::assertSame($original->riskScore, $restored->riskScore);
        self::assertSame($original->findingsCount, $restored->findingsCount);
        self::assertSame($original->assessedBy, $restored->assessedBy);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function aiImpactAssessedFromArrayDefaultsOnMissingKeys(): void
    {
        $event = AiImpactAssessed::fromArray([]);

        self::assertSame('', $event->modelId);
        self::assertSame(0.0, $event->riskScore);
        self::assertSame(0, $event->findingsCount);
        self::assertSame('', $event->assessedBy);
    }

    #[Test]
    public function aiImpactAssessedPreservesNumericTypes(): void
    {
        $event = new AiImpactAssessed(
            eventId: 'evt-num',
            occurredAt: new DateTimeImmutable(),
            correlationId: 'corr-num',
            nonce: 'nonce-num',
            modelId: 'model-num',
            riskScore: 0.0,
            findingsCount: 0,
            assessedBy: 'auto',
        );

        $array = $event->toArray();

        self::assertSame(0.0, $array['risk_score']);
        self::assertSame(0, $array['findings_count']);
    }

    // ── AiModelDeployed ─────────────────────────────────────────────

    #[Test]
    public function aiModelDeployedStoresProperties(): void
    {
        $now = new DateTimeImmutable('2026-03-15T13:00:00+00:00');
        $event = new AiModelDeployed(
            eventId: 'evt-4',
            occurredAt: $now,
            correlationId: 'corr-4',
            nonce: 'nonce-4',
            modelId: 'model-deploy',
            modelName: 'Fraud Detector',
            modelVersion: '2.1.0',
            riskLevel: 'high',
            deployedBy: 'mlops@example.com',
        );

        self::assertSame('model-deploy', $event->modelId);
        self::assertSame('Fraud Detector', $event->modelName);
        self::assertSame('2.1.0', $event->modelVersion);
        self::assertSame('high', $event->riskLevel);
        self::assertSame('mlops@example.com', $event->deployedBy);
    }

    #[Test]
    public function aiModelDeployedRegulationAndEventType(): void
    {
        $event = $this->createDeployEvent();

        self::assertSame('iso42001', $event->regulation());
        self::assertSame('ai_model_deployed', $event->eventType());
    }

    #[Test]
    public function aiModelDeployedRoundTrip(): void
    {
        $original = $this->createDeployEvent();
        $array = $original->toArray();
        $restored = AiModelDeployed::fromArray($array);

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->modelId, $restored->modelId);
        self::assertSame($original->modelName, $restored->modelName);
        self::assertSame($original->modelVersion, $restored->modelVersion);
        self::assertSame($original->riskLevel, $restored->riskLevel);
        self::assertSame($original->deployedBy, $restored->deployedBy);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function aiModelDeployedFromArrayDefaultsOnMissingKeys(): void
    {
        $event = AiModelDeployed::fromArray([]);

        self::assertSame('', $event->modelId);
        self::assertSame('', $event->modelName);
        self::assertSame('', $event->modelVersion);
        self::assertSame('', $event->riskLevel);
        self::assertSame('', $event->deployedBy);
    }

    // ── Cross-event: all share regulation ───────────────────────────

    #[Test]
    public function allEventsShareIso42001Regulation(): void
    {
        self::assertSame('iso42001', AiBiasDetected::fromArray([])->regulation());
        self::assertSame('iso42001', AiHumanOverride::fromArray([])->regulation());
        self::assertSame('iso42001', AiImpactAssessed::fromArray([])->regulation());
        self::assertSame('iso42001', AiModelDeployed::fromArray([])->regulation());
    }

    #[Test]
    #[DataProvider('eventTypeProvider')]
    public function eventTypesAreDistinct(string $class, string $expectedType): void
    {
        $event = match ($class) {
            AiBiasDetected::class => AiBiasDetected::fromArray([]),
            AiHumanOverride::class => AiHumanOverride::fromArray([]),
            AiImpactAssessed::class => AiImpactAssessed::fromArray([]),
            AiModelDeployed::class => AiModelDeployed::fromArray([]),
            default => self::fail("Unknown event class: {$class}"),
        };

        self::assertSame($expectedType, $event->eventType());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function eventTypeProvider(): iterable
    {
        yield 'AiBiasDetected' => [AiBiasDetected::class, 'ai_bias_detected'];
        yield 'AiHumanOverride' => [AiHumanOverride::class, 'ai_human_override'];
        yield 'AiImpactAssessed' => [AiImpactAssessed::class, 'ai_impact_assessed'];
        yield 'AiModelDeployed' => [AiModelDeployed::class, 'ai_model_deployed'];
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function createBiasEvent(): AiBiasDetected
    {
        return new AiBiasDetected(
            eventId: 'evt-bias',
            occurredAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            correlationId: 'corr-bias',
            nonce: 'nonce-bias',
            modelId: 'model-1',
            biasType: 'racial',
            affectedGroup: 'minority',
            description: 'Disparate impact detected',
            detectedBy: 'audit-tool',
        );
    }

    private function createOverrideEvent(): AiHumanOverride
    {
        return new AiHumanOverride(
            eventId: 'evt-override',
            occurredAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            correlationId: 'corr-override',
            nonce: 'nonce-override',
            modelId: 'model-2',
            decisionId: 'dec-1',
            overriddenBy: 'reviewer@example.com',
            reason: 'Incorrect classification',
        );
    }

    private function createImpactEvent(): AiImpactAssessed
    {
        return new AiImpactAssessed(
            eventId: 'evt-impact',
            occurredAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            correlationId: 'corr-impact',
            nonce: 'nonce-impact',
            modelId: 'model-3',
            riskScore: 0.42,
            findingsCount: 5,
            assessedBy: 'risk-analyst',
        );
    }

    private function createDeployEvent(): AiModelDeployed
    {
        return new AiModelDeployed(
            eventId: 'evt-deploy',
            occurredAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            correlationId: 'corr-deploy',
            nonce: 'nonce-deploy',
            modelId: 'model-4',
            modelName: 'Recommender',
            modelVersion: '1.0.0',
            riskLevel: 'medium',
            deployedBy: 'ops@example.com',
        );
    }
}
