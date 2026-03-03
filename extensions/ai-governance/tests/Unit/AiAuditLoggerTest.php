<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\AiGovernance\Enum\AiAuditEvent;
use Pulsar\Extension\AiGovernance\Internal\AiAuditLogger;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(AiAuditLogger::class)]
final class AiAuditLoggerTest extends TestCase
{
    private AuditLoggerInterface&MockObject $coreLogger;
    private AiAuditLogger $logger;

    protected function setUp(): void
    {
        $this->coreLogger = $this->createMock(AuditLoggerInterface::class);
        $this->logger = new AiAuditLogger($this->coreLogger);
    }

    public function testLogAiEventDelegatesToCoreLogger(): void
    {
        $this->coreLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SystemEvent,
                AuditOutcome::Success,
                self::callback(static fn(mixed $actor): bool => $actor instanceof AuditActor && $actor->id === 'system:ai-governance'),
                'ai.test_action',
                'test-resource',
                self::callback(static function (array $metadata): bool {
                    return $metadata['ai_event_type'] === AiAuditEvent::ModelDeployed->value
                        && $metadata['ai_model_id'] === 'model-1';
                }),
            );

        $this->logger->logAiEvent(
            event: AiAuditEvent::ModelDeployed,
            modelId: 'model-1',
            action: 'ai.test_action',
            resource: 'test-resource',
        );
    }

    public function testLogAiEventMergesCustomMetadata(): void
    {
        $this->coreLogger->expects(self::once())
            ->method('log')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (array $metadata): bool {
                    return $metadata['custom_key'] === 'custom_value'
                        && isset($metadata['ai_event_type'])
                        && isset($metadata['ai_model_id']);
                }),
            );

        $this->logger->logAiEvent(
            event: AiAuditEvent::BiasDetected,
            modelId: 'model-1',
            action: 'ai.bias_check',
            metadata: ['custom_key' => 'custom_value'],
        );
    }

    public function testLogInvocationCreatesCorrectAuditEvent(): void
    {
        $this->coreLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SystemEvent,
                AuditOutcome::Success,
                self::callback(static fn(mixed $actor): bool => $actor instanceof AuditActor && $actor->id === 'system:ai-governance'),
                'ai.invoke',
                'model-1',
                self::callback(static function (array $metadata): bool {
                    return $metadata['ai_event_type'] === AiAuditEvent::ModelInvoked->value
                        && $metadata['sanitized_input'] === ['prompt' => 'hello']
                        && $metadata['sanitized_output'] === ['response' => 'world']
                        && $metadata['confidence_score'] === 0.95;
                }),
            );

        $this->logger->logInvocation(
            modelId: 'model-1',
            sanitizedInput: ['prompt' => 'hello'],
            sanitizedOutput: ['response' => 'world'],
            confidenceScore: 0.95,
        );
    }

    public function testLogHumanOverrideCreatesCorrectAuditEvent(): void
    {
        $this->coreLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SystemEvent,
                AuditOutcome::Success,
                self::callback(static fn(mixed $actor): bool => $actor instanceof AuditActor && $actor->id === 'system:ai-governance'),
                'ai.human_override',
                'decision-42',
                self::callback(static function (array $metadata): bool {
                    return $metadata['ai_event_type'] === AiAuditEvent::HumanOverride->value
                        && $metadata['decision_id'] === 'decision-42'
                        && $metadata['override_reason'] === 'Model output was inappropriate'
                        && $metadata['overridden_by'] === 'admin@example.com';
                }),
            );

        $this->logger->logHumanOverride(
            modelId: 'model-1',
            decisionId: 'decision-42',
            reason: 'Model output was inappropriate',
            overriddenBy: 'admin@example.com',
        );
    }

    public function testLogHumanOverrideWithNullOverriddenBy(): void
    {
        $this->coreLogger->expects(self::once())
            ->method('log')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static function (array $metadata): bool {
                    return $metadata['overridden_by'] === null;
                }),
            );

        $this->logger->logHumanOverride(
            modelId: 'model-1',
            decisionId: 'decision-1',
            reason: 'Policy violation',
        );
    }
}
