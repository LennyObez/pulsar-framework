<?php

declare(strict_types=1);

namespace Pulsar\Webhook;

use DateTimeImmutable;
use JsonException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Webhook\Exception\WebhookException;
use Throwable;

use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Generic webhook processor: verify -> decode -> claim -> dispatch -> commit/release.
 *
 * HTTP-agnostic — returns WebhookProcessingResult, not a Response.
 * Domain-specific controllers map the result to their HTTP response format.
 */
#[Api(since: '1.0.0')]
final readonly class WebhookProcessor
{
    public function __construct(
        private WebhookVerifierInterface $verifier,
        private WebhookEventLogInterface $eventLog,
        private WebhookHandlerInterface $handler,
        private string $secret,
        private int $toleranceSeconds = 300,
        private int $deduplicationTtlSeconds = 259200,
        private string $eventIdKey = 'id',
        private string $eventTypeKey = 'type',
    ) {}

    /**
     * Process an incoming webhook payload.
     *
     * @param string $rawBody Raw request body bytes
     * @param string $signatureHeader Signature header value
     */
    #[NoDiscard]
    public function process(
        string $rawBody,
        string $signatureHeader,
        ?DateTimeImmutable $now = null,
    ): WebhookProcessingResult {
        $now ??= new DateTimeImmutable();

        // Step 1: Verify signature
        try {
            $this->verifier->verify($rawBody, $signatureHeader, $this->secret, $this->toleranceSeconds);
        } catch (WebhookException) {
            return new WebhookProcessingResult(WebhookProcessingStatus::InvalidSignature);
        }

        // Step 2: Decode payload
        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new WebhookProcessingResult(
                status: WebhookProcessingStatus::HandlerError,
                error: 'Invalid JSON payload',
            );
        }

        $eventIdRaw = $payload[$this->eventIdKey] ?? null;
        $eventTypeRaw = $payload[$this->eventTypeKey] ?? '';
        $eventId = is_string($eventIdRaw) ? $eventIdRaw : null;
        $eventType = is_string($eventTypeRaw) ? $eventTypeRaw : '';

        if ($eventId === null || $eventId === '') {
            return new WebhookProcessingResult(
                status: WebhookProcessingStatus::HandlerError,
                error: 'Missing event ID in payload',
            );
        }

        // Step 3: Claim for deduplication
        try {
            $claim = $this->eventLog->claim($eventId, $now, $this->deduplicationTtlSeconds);
        } catch (WebhookException) {
            return new WebhookProcessingResult(
                status: WebhookProcessingStatus::Replay,
                eventId: $eventId,
            );
        }

        if ($claim->status === WebhookClaimStatus::Replay) {
            return new WebhookProcessingResult(
                status: WebhookProcessingStatus::Replay,
                eventId: $eventId,
            );
        }

        // Step 4: Dispatch to handler
        try {
            $this->handler->handle($eventType, $payload);
        } catch (Throwable $e) {
            $this->eventLog->release($eventId);

            return new WebhookProcessingResult(
                status: WebhookProcessingStatus::HandlerError,
                eventId: $eventId,
                error: $e->getMessage(),
            );
        }

        // Step 5: Commit
        $this->eventLog->commit($eventId);

        return new WebhookProcessingResult(
            status: WebhookProcessingStatus::Processed,
            eventId: $eventId,
        );
    }
}
