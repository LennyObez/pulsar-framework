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
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Generic webhook processor: verify -> decode -> claim -> dispatch -> commit/release.
 *
 * HTTP-agnostic: returns WebhookProcessingResult, not a Response.
 * Domain-specific controllers map the result to their HTTP response format.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class WebhookProcessor
{
    /**
     * F25.6: webhook endpoints are forward-facing and a natural DoS
     * target — a 10 GB POST against `process()` would happily flow
     * into `json_decode()` on the worker thread and OOM. Cap the
     * raw body size before any further processing.
     *
     * 1 MiB is a deliberate compromise: large enough to accept every
     * documented webhook payload from Stripe / Mailgun / etc., small
     * enough that a 100 % DoS-load worker still has headroom to log
     * + reject. Operators who genuinely need larger payloads pass a
     * different value via constructor.
     */
    public const int DEFAULT_MAX_BODY_BYTES = 1_048_576; // 1 MiB

    /**
     * F25.6: webhook events are flat objects with one or two levels
     * of nesting in vendor specs. PHP's `json_decode` default depth
     * of 512 lets an attacker submit a deeply-nested JSON bomb that
     * stays under the 1 MiB body cap but still consumes excessive
     * stack / memory parsing it. 32 is more than enough for every
     * documented webhook shape.
     */
    private const int JSON_DECODE_MAX_DEPTH = 32;

    public function __construct(
        private WebhookVerifierInterface $verifier,
        private WebhookEventLogInterface $eventLog,
        private WebhookHandlerInterface $handler,
        private string $secret,
        private int $toleranceSeconds = 300,
        private int $deduplicationTtlSeconds = 259200,
        private string $eventIdKey = 'id',
        private string $eventTypeKey = 'type',
        private int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ) {
        if ($this->secret === '') {
            throw WebhookException::emptySecret();
        }
    }

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

        // F25.6: enforce the body cap BEFORE signature verification.
        // A 10 GB body that happens to ship a valid HMAC would still
        // OOM the worker; the cap also short-circuits cheap DoS that
        // doesn't bother forging a signature.
        if (strlen($rawBody) > $this->maxBodyBytes) {
            return new WebhookProcessingResult(
                status: WebhookProcessingStatus::HandlerError,
                error: 'Webhook payload exceeds size limit',
            );
        }

        // Step 1: Verify signature
        try {
            $this->verifier->verify($rawBody, $signatureHeader, $this->secret, $this->toleranceSeconds);
        } catch (WebhookException) {
            return new WebhookProcessingResult(WebhookProcessingStatus::InvalidSignature);
        }

        // Step 2: Decode payload (capped depth — F25.6).
        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($rawBody, true, self::JSON_DECODE_MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new WebhookProcessingResult(
                status: WebhookProcessingStatus::HandlerError,
                error: 'Invalid JSON payload',
            );
        }

        /** @var mixed $eventIdRaw */
        $eventIdRaw = $payload[$this->eventIdKey] ?? null;
        /** @var mixed $eventTypeRaw */
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
