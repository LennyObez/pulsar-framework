<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function abs;
use function is_array;
use function is_string;
use function json_decode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * Secure webhook handler with signature verification, replay protection,
 * and deduplication.
 *
 * Processing pipeline:
 * 1. Verify signature via WebhookVerifierInterface
 * 2. Check timestamp within replay window
 * 3. Deduplicate via event ID
 * 4. Parse and return result
 * 5. Audit log the event
 */
#[Internal]
final readonly class WebhookHandler implements WebhookHandlerInterface
{
    public function __construct(
        private WebhookVerifierInterface $verifier,
        private WebhookDeduplicationStoreInterface $deduplicationStore,
        private ?AuditLoggerInterface $auditLogger = null,
        private int $replayWindowSeconds = 300,
        private ?string $tenantId = null,
    ) {}

    public function handle(WebhookRequest $request): WebhookResult
    {
        if (!$this->verifier->verify($request)) {
            $this->logEvent($request, 'webhook.rejected', AuditOutcome::Denied, [
                'reason' => 'invalid_signature',
            ]);

            return new WebhookResult(
                accepted: false,
                eventId: '',
                eventType: WebhookEventType::Delivery,
            );
        }

        if ($this->isStale($request)) {
            $this->logEvent($request, 'webhook.rejected', AuditOutcome::Denied, [
                'reason' => 'stale_timestamp',
                'age_seconds' => abs(time() - $request->timestamp),
            ]);

            return new WebhookResult(
                accepted: false,
                eventId: '',
                eventType: WebhookEventType::Delivery,
            );
        }

        $decoded = json_decode($request->payload, true, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $data */
        $data = is_array($decoded) ? $decoded : [];

        $rawEventId = $data['event_id'] ?? '';
        $eventId = is_string($rawEventId) ? $rawEventId : '';

        $rawEventType = $data['event_type'] ?? '';
        $eventType = WebhookEventType::tryFrom(is_string($rawEventType) ? $rawEventType : '')
            ?? WebhookEventType::Delivery;

        $rawMessageId = $data['message_id'] ?? null;
        $messageId = is_string($rawMessageId) ? $rawMessageId : null;

        if ($eventId !== '' && $this->deduplicationStore->has($eventId, $this->tenantId)) {
            $this->logEvent($request, 'webhook.deduplicated', AuditOutcome::Success, [
                'event_id' => $eventId,
            ]);

            return WebhookResult::accepted($eventId, $eventType, $messageId);
        }

        if ($eventId !== '') {
            $this->deduplicationStore->store($eventId, $this->tenantId);
        }

        $this->logEvent($request, 'webhook.processed', AuditOutcome::Success, [
            'event_id' => $eventId,
            'event_type' => $eventType->value,
            'message_id' => $messageId,
        ]);

        return WebhookResult::accepted($eventId, $eventType, $messageId);
    }

    private function isStale(WebhookRequest $request): bool
    {
        return abs(time() - $request->timestamp) > $this->replayWindowSeconds;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function logEvent(
        WebhookRequest $request,
        string $action,
        AuditOutcome $outcome,
        array $metadata = [],
    ): void {
        $this->auditLogger?->log(
            event: AuditEvent::Communication,
            outcome: $outcome,
            actor: null,
            action: $action,
            resource: $request->provider,
            metadata: [
                'provider' => $request->provider,
                'source_ip' => $request->sourceIp,
                ...$metadata,
            ],
        );
    }
}
