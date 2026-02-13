<?php

declare(strict_types=1);

namespace Pulsar\Queue\Middleware;

use Closure;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Queue\Attribute\EffectClassifier;
use Pulsar\Queue\Envelope\JobEnvelope;

/**
 * Encrypts/decrypts job payloads using AEAD (XChaCha20-Poly1305) with AAD binding.
 *
 * AAD composition: tenant_id | queue | job_class | schema_version | correlation_id | attempt
 * This binds the ciphertext to the envelope context: any modification to AAD fields
 * will cause decryption to fail, providing tamper detection.
 *
 * On dispatch: if the job class has the #[Encrypted] attribute, encrypts the payload
 * and stores the key_id in the envelope for rotation support.
 *
 * On execution: if the envelope is marked as encrypted, decrypts the payload with
 * AAD verification before passing to the next middleware.
 *
 * Transport/driver IDs are intentionally excluded from AAD because they break
 * legitimate workflows (dev Redis -> prod SQS, migrations, failover). Cross-env
 * replay is prevented by key separation (distinct master keys per environment).
 */
#[Internal(reason: 'Encryption middleware is an implementation detail of the queue transport')]
final readonly class EncryptPayload implements JobMiddlewareInterface
{
    public function __construct(
        private AeadPayloadEncryptor $encryptor,
        private EffectClassifier $classifier,
    ) {}

    #[Override]
    public function handle(JobEnvelope $envelope, Closure $next): mixed
    {
        $processed = $this->processEnvelope($envelope);

        return $next($processed);
    }

    private function processEnvelope(JobEnvelope $envelope): JobEnvelope
    {
        if ($envelope->encrypted) {
            return $this->decrypt($envelope);
        }

        /** @var class-string $encJobClass */
        $encJobClass = $envelope->jobClass;
        if ($this->classifier->isEncrypted($encJobClass)) {
            return $this->encrypt($envelope);
        }

        return $envelope;
    }

    private function encrypt(JobEnvelope $envelope): JobEnvelope
    {
        $aad = AeadPayloadEncryptor::composeAad(
            tenantId: $envelope->tenantId,
            queue: $envelope->queue,
            jobClass: $envelope->jobClass,
            schemaVersion: $envelope->schemaVersion,
            correlationId: $envelope->correlationId,
        );

        $result = $this->encryptor->encrypt($envelope->payload, $aad);

        return new JobEnvelope(
            id: $envelope->id,
            jobClass: $envelope->jobClass,
            payload: $result['ciphertext'],
            queue: $envelope->queue,
            idempotencyKey: $envelope->idempotencyKey,
            correlationId: $envelope->correlationId,
            traceId: $envelope->traceId,
            spanId: $envelope->spanId,
            schemaVersion: $envelope->schemaVersion,
            keyId: $result['keyId'],
            retryMaxAttempts: $envelope->retryMaxAttempts,
            retryBackoffStrategy: $envelope->retryBackoffStrategy,
            retryDelayMs: $envelope->retryDelayMs,
            tenantId: $envelope->tenantId,
            subjectId: $envelope->subjectId,
            batchId: $envelope->batchId,
            chainIndex: $envelope->chainIndex,
            attempt: $envelope->attempt,
            dispatchedAt: $envelope->dispatchedAt,
            encrypted: true,
            metadata: $envelope->metadata,
        );
    }

    private function decrypt(JobEnvelope $envelope): JobEnvelope
    {
        $aad = AeadPayloadEncryptor::composeAad(
            tenantId: $envelope->tenantId,
            queue: $envelope->queue,
            jobClass: $envelope->jobClass,
            schemaVersion: $envelope->schemaVersion,
            correlationId: $envelope->correlationId,
        );

        $plaintext = $this->encryptor->decrypt($envelope->payload, $aad, $envelope->keyId);

        return new JobEnvelope(
            id: $envelope->id,
            jobClass: $envelope->jobClass,
            payload: $plaintext,
            queue: $envelope->queue,
            idempotencyKey: $envelope->idempotencyKey,
            correlationId: $envelope->correlationId,
            traceId: $envelope->traceId,
            spanId: $envelope->spanId,
            schemaVersion: $envelope->schemaVersion,
            keyId: $envelope->keyId,
            retryMaxAttempts: $envelope->retryMaxAttempts,
            retryBackoffStrategy: $envelope->retryBackoffStrategy,
            retryDelayMs: $envelope->retryDelayMs,
            tenantId: $envelope->tenantId,
            subjectId: $envelope->subjectId,
            batchId: $envelope->batchId,
            chainIndex: $envelope->chainIndex,
            attempt: $envelope->attempt,
            dispatchedAt: $envelope->dispatchedAt,
            encrypted: false,
            metadata: $envelope->metadata,
        );
    }
}
