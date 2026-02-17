<?php

declare(strict_types=1);

namespace Pulsar\Queue\Envelope;

use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Queue\Exception\QueueException;

use function array_key_exists;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Serializes and deserializes {@see JobEnvelope} instances to/from JSON.
 *
 * This serializer handles the full envelope structure including all metadata.
 * It never uses PHP {@see unserialize()} — all data flows through typed JSON.
 */
#[Internal(reason: 'Envelope serialization is an implementation detail of the queue transport')]
final readonly class EnvelopeSerializer
{
    private const REQUIRED_FIELDS = [
        'id',
        'jobClass',
        'payload',
        'queue',
        'idempotencyKey',
        'correlationId',
        'schemaVersion',
        'retryMaxAttempts',
        'retryBackoffStrategy',
        'retryDelayMs',
        'attempt',
        'dispatchedAt',
        'encrypted',
    ];

    /**
     * Serialize a job envelope to a JSON string.
     *
     * @throws JsonException If JSON encoding fails.
     */
    public function serialize(JobEnvelope $envelope): string
    {
        $data = [
            'id' => $envelope->id,
            'jobClass' => $envelope->jobClass,
            'payload' => $envelope->payload,
            'queue' => $envelope->queue,
            'idempotencyKey' => $envelope->idempotencyKey,
            'correlationId' => $envelope->correlationId,
            'traceId' => $envelope->traceId,
            'spanId' => $envelope->spanId,
            'schemaVersion' => $envelope->schemaVersion,
            'keyId' => $envelope->keyId,
            'retryMaxAttempts' => $envelope->retryMaxAttempts,
            'retryBackoffStrategy' => $envelope->retryBackoffStrategy->value,
            'retryDelayMs' => $envelope->retryDelayMs,
            'tenantId' => $envelope->tenantId,
            'subjectId' => $envelope->subjectId,
            'batchId' => $envelope->batchId,
            'chainIndex' => $envelope->chainIndex,
            'attempt' => $envelope->attempt,
            'dispatchedAt' => $envelope->dispatchedAt,
            'encrypted' => $envelope->encrypted,
            'metadata' => $envelope->metadata,
        ];

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Deserialize a JSON string into a job envelope.
     *
     * @throws QueueException  If required fields are missing or data is invalid.
     * @throws JsonException  If JSON decoding fails.
     */
    public function deserialize(string $json): JobEnvelope
    {
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw QueueException::invalidEnvelope('decoded value is not an object');
        }

        /** @var array<string, mixed> $data */
        $data = $data;

        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                throw QueueException::missingEnvelopeField($field);
            }
        }

        $rawBackoff = $this->string($data, 'retryBackoffStrategy');
        $backoffStrategy = BackoffStrategy::tryFrom($rawBackoff);

        if ($backoffStrategy === null) {
            throw QueueException::invalidEnvelope('unknown backoff strategy "' . $rawBackoff . '"');
        }

        /** @var array<string, mixed> $metadata */
        $metadata = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [];

        return new JobEnvelope(
            id: $this->string($data, 'id'),
            jobClass: $this->string($data, 'jobClass'),
            payload: $this->string($data, 'payload'),
            queue: $this->string($data, 'queue'),
            idempotencyKey: $this->string($data, 'idempotencyKey'),
            correlationId: $this->string($data, 'correlationId'),
            traceId: $this->nullableString($data, 'traceId'),
            spanId: $this->nullableString($data, 'spanId'),
            schemaVersion: $this->int($data, 'schemaVersion'),
            keyId: $this->nullableString($data, 'keyId'),
            retryMaxAttempts: $this->int($data, 'retryMaxAttempts'),
            retryBackoffStrategy: $backoffStrategy,
            retryDelayMs: $this->int($data, 'retryDelayMs'),
            tenantId: $this->nullableString($data, 'tenantId'),
            subjectId: $this->nullableString($data, 'subjectId'),
            batchId: $this->nullableString($data, 'batchId'),
            chainIndex: $this->nullableInt($data, 'chainIndex'),
            attempt: $this->int($data, 'attempt'),
            dispatchedAt: $this->int($data, 'dispatchedAt'),
            encrypted: $this->bool($data, 'encrypted'),
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws QueueException
     */
    private function string(array $data, string $field): string
    {
        if (!is_string($data[$field])) {
            throw QueueException::invalidEnvelope('field "' . $field . '" must be a string');
        }

        return $data[$field];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws QueueException
     */
    private function nullableString(array $data, string $field): ?string
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            return null;
        }

        if (!is_string($data[$field])) {
            throw QueueException::invalidEnvelope('field "' . $field . '" must be a string or null');
        }

        return $data[$field];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws QueueException
     */
    private function int(array $data, string $field): int
    {
        if (!is_int($data[$field])) {
            throw QueueException::invalidEnvelope('field "' . $field . '" must be an integer');
        }

        return $data[$field];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws QueueException
     */
    private function nullableInt(array $data, string $field): ?int
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            return null;
        }

        if (!is_int($data[$field])) {
            throw QueueException::invalidEnvelope('field "' . $field . '" must be an integer or null');
        }

        return $data[$field];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws QueueException
     */
    private function bool(array $data, string $field): bool
    {
        if (!is_bool($data[$field])) {
            throw QueueException::invalidEnvelope('field "' . $field . '" must be a boolean');
        }

        return $data[$field];
    }
}
