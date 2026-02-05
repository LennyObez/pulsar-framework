<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Event;

use function bin2hex;
use function gethostname;
use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use Pulsar\Api\Internal;
use Pulsar\Studio\CorrelationContext;

use function random_bytes;

/**
 * Factory for creating EventEnvelopes from ConsoleEvents.
 */
#[Internal]
final readonly class EventFactory
{
    public function __construct(
        private string $appEnv,
        private string $hostname,
    ) {}

    /**
     * Create a factory with auto-detected hostname.
     */
    public static function create(string $appEnv): self
    {
        return new self(
            appEnv: $appEnv,
            hostname: gethostname() ?: 'unknown',
        );
    }

    /**
     * Wrap a ConsoleEvent into an EventEnvelope with metadata.
     */
    public function envelope(ConsoleEvent $event, ?CorrelationContext $context = null): EventEnvelope
    {
        $context ??= new CorrelationContext();
        $payload = $event->toArray();
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadHash = hash('sha256', $payloadJson);

        return new EventEnvelope(
            eventId: bin2hex(random_bytes(16)),
            eventType: $event->eventType(),
            schemaVersion: $event->schemaVersion(),
            timestampUs: (int) (microtime(true) * 1_000_000.0),
            requestId: $context->requestId,
            traceId: $context->traceId,
            spanId: $context->spanId,
            jobId: $context->jobId,
            appEnv: $this->appEnv,
            hostname: $this->hostname,
            payload: $payload,
            payloadHash: $payloadHash,
        );
    }
}
