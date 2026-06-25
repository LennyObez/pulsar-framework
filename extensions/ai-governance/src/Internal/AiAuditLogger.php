<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiAuditLoggerInterface;
use Pulsar\Extension\AiGovernance\Enum\AiAuditEvent;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

/**
 * AI audit logger that delegates to the core HMAC-chained audit trail.
 *
 * Maps AI governance events to the core audit logging system, ensuring
 * all AI-specific events participate in the tamper-evident HMAC chain.
 */
#[Internal(reason: 'Internal implementation; use AiAuditLoggerInterface for public access')]
final readonly class AiAuditLogger implements AiAuditLoggerInterface
{
    public function __construct(
        private AuditLoggerInterface $auditLogger,
        private bool $auditInvocations = true,
    ) {}

    #[Override]
    public function logAiEvent(
        AiAuditEvent $event,
        string $modelId,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): void {
        $metadata['ai_event_type'] = $event->value;
        $metadata['ai_model_id'] = $modelId;

        $this->auditLogger->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: AuditActor::system('ai-governance'),
            action: $action,
            resource: $resource,
            metadata: $metadata,
        );
    }

    #[Override]
    public function logInvocation(
        string $modelId,
        array $sanitizedInput,
        array $sanitizedOutput,
        float $confidenceScore,
    ): void {
        // Invocation auditing is opt-out via config; when disabled, model
        // invocations are not written to the audit trail.
        if (! $this->auditInvocations) {
            return;
        }

        $this->logAiEvent(
            event: AiAuditEvent::ModelInvoked,
            modelId: $modelId,
            action: 'ai.invoke',
            resource: $modelId,
            metadata: [
                'sanitized_input' => $sanitizedInput,
                'sanitized_output' => $sanitizedOutput,
                'confidence_score' => $confidenceScore,
            ],
        );
    }

    #[Override]
    public function logHumanOverride(
        string $modelId,
        string $decisionId,
        string $reason,
        ?string $overriddenBy = null,
    ): void {
        $this->logAiEvent(
            event: AiAuditEvent::HumanOverride,
            modelId: $modelId,
            action: 'ai.human_override',
            resource: $decisionId,
            metadata: [
                'decision_id' => $decisionId,
                'override_reason' => $reason,
                'overridden_by' => $overriddenBy,
            ],
        );
    }
}
