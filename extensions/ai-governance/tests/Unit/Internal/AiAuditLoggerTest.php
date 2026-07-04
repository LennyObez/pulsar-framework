<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\AiGovernance\Enum\AiAuditEvent;
use Pulsar\Extension\AiGovernance\Internal\AiAuditLogger;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(AiAuditLogger::class)]
final class AiAuditLoggerTest extends TestCase
{
    /** @var list<array{event: AuditEvent, outcome: AuditOutcome, action: string, metadata: array<string, mixed>}> */
    private array $logged = [];

    private AiAuditLogger $logger;

    protected function setUp(): void
    {
        $auditEntry = $this->createStub(AuditEntry::class);
        $coreLogger = $this->createStub(AuditLoggerInterface::class);
        $coreLogger->method('log')
            ->willReturnCallback(function (
                AuditEvent $event,
                AuditOutcome $outcome,
                mixed $actor,
                string $action,
                string $resource = '',
                array $metadata = [],
            ) use ($auditEntry): AuditEntry {
                $this->logged[] = [
                    'event' => $event,
                    'outcome' => $outcome,
                    'action' => $action,
                    'resource' => $resource,
                    'metadata' => $metadata,
                ];

                return $auditEntry;
            });

        $this->logger = new AiAuditLogger($coreLogger);
    }

    #[Test]
    public function logAiEventDelegatesToCoreLogger(): void
    {
        $this->logger->logAiEvent(
            event: AiAuditEvent::ModelDeployed,
            modelId: 'model-1',
            action: 'ai.model_deployed',
            resource: 'model-1',
            metadata: ['version' => '2.0'],
        );

        self::assertCount(1, $this->logged);
        self::assertSame(AuditEvent::SystemEvent, $this->logged[0]['event']);
        self::assertSame(AuditOutcome::Success, $this->logged[0]['outcome']);
        self::assertSame('ai.model_deployed', $this->logged[0]['action']);
        self::assertSame('ai.model_deployed', $this->logged[0]['metadata']['ai_event_type']);
        self::assertSame('model-1', $this->logged[0]['metadata']['ai_model_id']);
        self::assertSame('2.0', $this->logged[0]['metadata']['version']);
    }

    #[Test]
    public function logInvocationRecordsInputOutputAndConfidence(): void
    {
        $this->logger->logInvocation(
            modelId: 'model-2',
            sanitizedInput: ['prompt' => 'test query'],
            sanitizedOutput: ['response' => 'test answer'],
            confidenceScore: 0.87,
        );

        self::assertCount(1, $this->logged);
        self::assertSame('ai.invoke', $this->logged[0]['action']);
        $meta = $this->logged[0]['metadata'];
        self::assertSame('ai.model_invoked', $meta['ai_event_type']);
        self::assertSame('model-2', $meta['ai_model_id']);
        self::assertSame(['prompt' => 'test query'], $meta['sanitized_input']);
        self::assertSame(['response' => 'test answer'], $meta['sanitized_output']);
        self::assertSame(0.87, $meta['confidence_score']);
    }

    #[Test]
    public function logInvocationIsSuppressedWhenAuditingDisabled(): void
    {
        $coreLogger = $this->createMock(AuditLoggerInterface::class);
        $coreLogger->expects(self::never())->method('log');

        $logger = new AiAuditLogger($coreLogger, auditInvocations: false);
        $logger->logInvocation(
            modelId: 'model-x',
            sanitizedInput: ['prompt' => 'test'],
            sanitizedOutput: ['response' => 'answer'],
            confidenceScore: 0.5,
        );
    }

    #[Test]
    public function logHumanOverrideRecordsReasonAndOverrider(): void
    {
        $this->logger->logHumanOverride(
            modelId: 'model-3',
            decisionId: 'decision-42',
            reason: 'Model suggestion was incorrect for edge case',
            overriddenBy: 'user-admin',
        );

        self::assertCount(1, $this->logged);
        self::assertSame('ai.human_override', $this->logged[0]['action']);
        self::assertSame('decision-42', $this->logged[0]['resource']);
        $meta = $this->logged[0]['metadata'];
        self::assertSame('decision-42', $meta['decision_id']);
        self::assertSame('Model suggestion was incorrect for edge case', $meta['override_reason']);
        self::assertSame('user-admin', $meta['overridden_by']);
    }

    #[Test]
    public function logHumanOverrideWithNullOverrider(): void
    {
        $this->logger->logHumanOverride(
            modelId: 'model-3',
            decisionId: 'decision-43',
            reason: 'Manual correction',
        );

        $meta = $this->logged[0]['metadata'];
        self::assertNull($meta['overridden_by']);
    }
}
