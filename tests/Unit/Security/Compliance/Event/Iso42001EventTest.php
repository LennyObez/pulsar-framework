<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Iso42001\AiBiasDetected;
use Pulsar\Security\Compliance\Event\Iso42001\AiHumanOverride;
use Pulsar\Security\Compliance\Event\Iso42001\AiImpactAssessed;
use Pulsar\Security\Compliance\Event\Iso42001\AiModelDeployed;

#[CoversClass(AiModelDeployed::class)]
#[CoversClass(AiImpactAssessed::class)]
#[CoversClass(AiHumanOverride::class)]
#[CoversClass(AiBiasDetected::class)]
final class Iso42001EventTest extends TestCase
{
    private const string TS = '2026-03-15T10:00:00.000000+00:00';

    public function testAiModelDeployedRoundTrip(): void
    {
        $event = new AiModelDeployed(
            eventId: 'evt-1',
            occurredAt: new DateTimeImmutable(self::TS),
            correlationId: 'corr-1',
            nonce: 'n1',
            modelId: 'model-gpt4',
            modelName: 'Risk Scorer',
            modelVersion: 'v2.1',
            riskLevel: 'medium',
            deployedBy: 'ml-ops-1',
        );

        self::assertSame('iso42001', $event->regulation());
        self::assertSame('ai_model_deployed', $event->eventType());
        self::assertSame(1, AiModelDeployed::SCHEMA_VERSION);

        $restored = AiModelDeployed::fromArray($event->toArray());
        self::assertSame('model-gpt4', $restored->modelId);
        self::assertSame('Risk Scorer', $restored->modelName);
        self::assertSame('v2.1', $restored->modelVersion);
        self::assertSame('medium', $restored->riskLevel);
        self::assertSame('ml-ops-1', $restored->deployedBy);
    }

    public function testAiImpactAssessedRoundTrip(): void
    {
        $event = new AiImpactAssessed(
            eventId: 'evt-2',
            occurredAt: new DateTimeImmutable(self::TS),
            correlationId: 'corr-2',
            nonce: 'n2',
            modelId: 'model-bert',
            riskScore: 0.75,
            findingsCount: 3,
            assessedBy: 'risk-team',
        );

        self::assertSame('iso42001', $event->regulation());
        self::assertSame('ai_impact_assessed', $event->eventType());

        $restored = AiImpactAssessed::fromArray($event->toArray());
        self::assertSame('model-bert', $restored->modelId);
        self::assertSame(0.75, $restored->riskScore);
        self::assertSame(3, $restored->findingsCount);
        self::assertSame('risk-team', $restored->assessedBy);
    }

    public function testAiHumanOverrideRoundTrip(): void
    {
        $event = new AiHumanOverride(
            eventId: 'evt-3',
            occurredAt: new DateTimeImmutable(self::TS),
            correlationId: 'corr-3',
            nonce: 'n3',
            modelId: 'model-loan',
            decisionId: 'decision-42',
            overriddenBy: 'loan-officer-7',
            reason: 'Insufficient context for borderline case',
        );

        self::assertSame('iso42001', $event->regulation());
        self::assertSame('ai_human_override', $event->eventType());

        $restored = AiHumanOverride::fromArray($event->toArray());
        self::assertSame('model-loan', $restored->modelId);
        self::assertSame('decision-42', $restored->decisionId);
        self::assertSame('loan-officer-7', $restored->overriddenBy);
        self::assertSame('Insufficient context for borderline case', $restored->reason);
    }

    public function testAiBiasDetectedRoundTrip(): void
    {
        $event = new AiBiasDetected(
            eventId: 'evt-4',
            occurredAt: new DateTimeImmutable(self::TS),
            correlationId: 'corr-4',
            nonce: 'n4',
            modelId: 'model-hiring',
            biasType: 'demographic_parity',
            affectedGroup: 'age_40_plus',
            description: 'Approval rate 15% lower for age 40+ applicants',
            detectedBy: 'fairness-monitor',
        );

        self::assertSame('iso42001', $event->regulation());
        self::assertSame('ai_bias_detected', $event->eventType());

        $restored = AiBiasDetected::fromArray($event->toArray());
        self::assertSame('model-hiring', $restored->modelId);
        self::assertSame('demographic_parity', $restored->biasType);
        self::assertSame('age_40_plus', $restored->affectedGroup);
        self::assertSame('fairness-monitor', $restored->detectedBy);
    }

    public function testFromArrayWithMissingDataUsesDefaults(): void
    {
        $event = AiModelDeployed::fromArray([]);

        self::assertSame('', $event->modelId);
        self::assertSame('', $event->modelName);
        self::assertSame('', $event->deployedBy);

        $impact = AiImpactAssessed::fromArray([]);
        self::assertSame(0.0, $impact->riskScore);
        self::assertSame(0, $impact->findingsCount);
    }

    public function testAllEventsShareBaseFields(): void
    {
        $events = [
            new AiModelDeployed('e1', new DateTimeImmutable(), 'c', 'n', 'id', 'name', 'v1', 'low', 'ops'),
            new AiImpactAssessed('e2', new DateTimeImmutable(), 'c', 'n', 'id', 0.5, 1, 'assessor'),
            new AiHumanOverride('e3', new DateTimeImmutable(), 'c', 'n', 'id', 'd1', 'user', 'reason'),
            new AiBiasDetected('e4', new DateTimeImmutable(), 'c', 'n', 'id', 'type', 'group', 'desc', 'detector'),
        ];

        foreach ($events as $event) {
            self::assertSame('iso42001', $event->regulation());
            self::assertSame('c', $event->correlationId);
            self::assertSame('n', $event->nonce);
        }
    }
}
