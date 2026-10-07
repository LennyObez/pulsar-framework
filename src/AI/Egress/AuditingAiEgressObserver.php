<?php

declare(strict_types=1);

namespace Pulsar\AI\Egress;

use Override;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

/**
 * Writes every egress decision to the audit chain.
 *
 * The observer an operator wires when the answer to "show me what this
 * deployment tried to send to a third party" has to survive being asked a year
 * later. Refusals arrive as {@see AuditOutcome::Denied}, which is what makes them
 * findable among the successes.
 *
 * It records the decision's own metadata — counts and kinds of what was found,
 * from {@see AiEgressDecision::toAuditMetadata()} — and never the prompt. The
 * audit chain is tamper-evident storage, not a place to escrow the personal data
 * a refusal just protected.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AuditingAiEgressObserver implements AiEgressObserverInterface
{
    public function __construct(
        private AuditLoggerInterface $auditLogger,
    ) {}

    #[Override]
    public function decided(AiEgressDecision $decision): void
    {
        $this->auditLogger->log(
            event: AuditEvent::SecurityEvent,
            outcome: match ($decision->outcome) {
                AiEgressOutcome::Refused => AuditOutcome::Denied,
                AiEgressOutcome::Redacted, AiEgressOutcome::Allowed => AuditOutcome::Success,
            },
            actor: AuditActor::system('ai.egress'),
            action: 'ai.egress.' . $decision->outcome->value,
            resource: $decision->destination->describe(),
            metadata: $decision->toAuditMetadata(),
        );
    }
}
