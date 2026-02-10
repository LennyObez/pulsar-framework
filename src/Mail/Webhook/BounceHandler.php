<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Mail\Audit\DeliveryStatus;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function is_array;
use function is_string;
use function json_decode;

/**
 * Processes bounce webhook events.
 *
 * Updates delivery status for bounced messages and audit logs the event.
 */
#[Internal]
final readonly class BounceHandler
{
    public function __construct(
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Process a bounce event from a webhook payload.
     *
     * @return array{message_id: ?string, bounce_type: string, delivery_status: DeliveryStatus}
     */
    public function process(WebhookRequest $request): array
    {
        $decoded = json_decode($request->payload, true);
        /** @var array<string, mixed> $data */
        $data = is_array($decoded) ? $decoded : [];

        $rawMessageId = $data['message_id'] ?? null;
        $messageId = is_string($rawMessageId) ? $rawMessageId : null;

        $rawBounceType = $data['bounce_type'] ?? null;
        $bounceType = is_string($rawBounceType) ? $rawBounceType : 'unknown';

        $this->auditLogger?->log(
            event: AuditEvent::Communication,
            outcome: AuditOutcome::Failure,
            actor: null,
            action: 'mail.bounce',
            resource: $messageId ?? '',
            metadata: [
                'provider' => $request->provider,
                'bounce_type' => $bounceType,
                'delivery_status' => DeliveryStatus::Bounced->value,
            ],
        );

        return [
            'message_id' => $messageId,
            'bounce_type' => $bounceType,
            'delivery_status' => DeliveryStatus::Bounced,
        ];
    }
}
