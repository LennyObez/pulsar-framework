<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function is_array;
use function is_string;
use function json_decode;

/**
 * Processes complaint/spam-report webhook events.
 *
 * Logs the complaint for audit purposes. Downstream consumers can use
 * the result to trigger unsubscribe workflows.
 */
#[Internal]
final readonly class ComplaintHandler
{
    public function __construct(
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Process a complaint event from a webhook payload.
     *
     * @return array{message_id: ?string, complaint_type: string, should_unsubscribe: bool}
     */
    public function process(WebhookRequest $request): array
    {
        $decoded = json_decode($request->payload, true);
        /** @var array<string, mixed> $data */
        $data = is_array($decoded) ? $decoded : [];

        $rawMessageId = $data['message_id'] ?? null;
        $messageId = is_string($rawMessageId) ? $rawMessageId : null;

        $rawComplaintType = $data['complaint_type'] ?? null;
        $complaintType = is_string($rawComplaintType) ? $rawComplaintType : 'abuse';

        $shouldUnsubscribe = $complaintType === 'abuse' || $complaintType === 'spam';

        $this->auditLogger?->log(
            event: AuditEvent::Communication,
            outcome: AuditOutcome::Success,
            actor: null,
            action: 'mail.complaint',
            resource: $messageId ?? '',
            metadata: [
                'provider' => $request->provider,
                'complaint_type' => $complaintType,
                'should_unsubscribe' => $shouldUnsubscribe,
            ],
        );

        return [
            'message_id' => $messageId,
            'complaint_type' => $complaintType,
            'should_unsubscribe' => $shouldUnsubscribe,
        ];
    }
}
