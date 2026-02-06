<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Event;

use function bin2hex;
use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Studio\CorrelationContext;
use Random\RandomException;

use function random_bytes;

/**
 * Wraps a ConsoleEvent with common metadata and computed fields.
 */
#[Internal]
final readonly class EventEnvelope
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $eventId,
        public EventType $eventType,
        public EventVersion $schemaVersion,
        public int $timestampUs,
        public ?string $requestId,
        public ?string $traceId,
        public ?string $spanId,
        public ?string $jobId,
        public string $appEnv,
        public string $hostname,
        public array $payload,
        public string $payloadHash,
    ) {}

    /**
     * Create an envelope from a ConsoleEvent and its correlation context.
     *
     * @throws JsonException
     * @throws RandomException
     */
    public static function wrap(
        ConsoleEvent $event,
        CorrelationContext $context,
        string $appEnv,
        string $hostname,
    ): self {
        $payload = $event->toArray();
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadHash = hash('sha256', $payloadJson);

        return new self(
            eventId: bin2hex(random_bytes(16)),
            eventType: $event->eventType(),
            schemaVersion: $event->schemaVersion(),
            timestampUs: (int) (microtime(true) * 1_000_000.0),
            requestId: $context->requestId,
            traceId: $context->traceId,
            spanId: $context->spanId,
            jobId: $context->jobId,
            appEnv: $appEnv,
            hostname: $hostname,
            payload: $payload,
            payloadHash: $payloadHash,
        );
    }

    /**
     * Build the canonical string used for evidence chain hashing.
     */
    public function canonical(): string
    {
        return ($this->eventId)
            . '|' . ($this->eventType->value)
            . '|' . ($this->schemaVersion->value)
            . '|' . ($this->timestampUs)
            . '|' . ($this->traceId ?? '')
            . '|' . ($this->payloadHash);
    }
}
