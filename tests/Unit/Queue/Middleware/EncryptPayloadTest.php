<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Attribute\EffectClassifier;
use Pulsar\Queue\Attribute\Encrypted;
use Pulsar\Queue\Attribute\Idempotent;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Middleware\AeadPayloadEncryptor;
use Pulsar\Queue\Middleware\EncryptPayload;
use Pulsar\Security\Crypto\KeyRingInterface;
use Pulsar\Security\Crypto\MasterKey;

use function sodium_bin2hex;

#[Encrypted]
#[Idempotent]
final class EncryptedStubJobForMiddleware {}

#[Idempotent]
final class PlainStubJobForMiddleware {}

#[CoversClass(EncryptPayload::class)]
final class EncryptPayloadTest extends TestCase
{
    private AeadPayloadEncryptor $encryptor;
    private EffectClassifier $classifier;
    private string $testKid;

    protected function setUp(): void
    {
        $masterKey = MasterKey::fromHex(sodium_bin2hex(random_bytes(32)));
        $derivedKey = $masterKey->deriveSubKey(10, 'que_aead', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $this->testKid = $masterKey->keyId(10, 'que_aead');

        $keyRing = $this->createStub(KeyRingInterface::class);
        $keyRing->method('keyFor')->willReturn($derivedKey);
        $keyRing->method('all')->willReturn([$this->testKid => $derivedKey]);

        $this->encryptor = new AeadPayloadEncryptor($masterKey, $keyRing);
        $this->classifier = new EffectClassifier();
    }

    private function createEnvelope(
        string $jobClass,
        bool $encrypted = false,
        string $payload = '{"amount":100}',
        ?string $keyId = null,
    ): JobEnvelope {
        return new JobEnvelope(
            id: 'job-enc-001',
            jobClass: $jobClass,
            payload: $payload,
            queue: 'payments',
            idempotencyKey: 'idem-key-001',
            correlationId: 'corr-001',
            traceId: null,
            spanId: null,
            schemaVersion: 1,
            keyId: $keyId,
            retryMaxAttempts: 3,
            retryBackoffStrategy: BackoffStrategy::Exponential,
            retryDelayMs: 1000,
            tenantId: 'tenant-1',
            subjectId: 'user-42',
            batchId: null,
            chainIndex: null,
            attempt: 1,
            dispatchedAt: 1709827200,
            encrypted: $encrypted,
        );
    }

    #[Test]
    public function encryptsPayloadWhenJobClassIsEncrypted(): void
    {
        $middleware = new EncryptPayload($this->encryptor, $this->classifier);
        $envelope = $this->createEnvelope(EncryptedStubJobForMiddleware::class);

        $result = null;
        $middleware->handle($envelope, static function (JobEnvelope $e) use (&$result): string {
            $result = $e;

            return 'done';
        });

        self::assertInstanceOf(JobEnvelope::class, $result);
        self::assertNotSame('{"amount":100}', $result->payload);
        self::assertTrue($result->encrypted);
        self::assertSame($this->testKid, $result->keyId);
    }

    #[Test]
    public function decryptsPayloadWhenEnvelopeIsEncrypted(): void
    {
        $middleware = new EncryptPayload($this->encryptor, $this->classifier);

        // First encrypt
        $envelope = $this->createEnvelope(EncryptedStubJobForMiddleware::class);
        $encrypted = null;
        $middleware->handle($envelope, static function (JobEnvelope $e) use (&$encrypted): string {
            $encrypted = $e;

            return 'done';
        });

        self::assertInstanceOf(JobEnvelope::class, $encrypted);

        // Now decrypt
        $decrypted = null;
        $middleware->handle($encrypted, static function (JobEnvelope $e) use (&$decrypted): string {
            $decrypted = $e;

            return 'done';
        });

        self::assertInstanceOf(JobEnvelope::class, $decrypted);
        self::assertSame('{"amount":100}', $decrypted->payload);
        self::assertFalse($decrypted->encrypted);
    }

    #[Test]
    public function passesEnvelopeThroughWhenNotEncryptedAndClassNotMarked(): void
    {
        $middleware = new EncryptPayload($this->encryptor, $this->classifier);
        $envelope = $this->createEnvelope(PlainStubJobForMiddleware::class);

        $result = null;
        $middleware->handle($envelope, static function (JobEnvelope $e) use (&$result): string {
            $result = $e;

            return 'done';
        });

        self::assertSame($envelope, $result);
    }
}
