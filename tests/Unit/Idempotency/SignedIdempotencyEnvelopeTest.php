<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Idempotency;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Idempotency\Exception\IdempotencyException;
use Pulsar\Idempotency\SignedIdempotencyEnvelope;
use Pulsar\Security\Crypto\MasterKey;

use function base64_decode;
use function base64_encode;
use function json_decode;
use function json_encode;
use function random_bytes;
use function sodium_bin2hex;
use function strrev;

#[CoversClass(SignedIdempotencyEnvelope::class)]
final class SignedIdempotencyEnvelopeTest extends TestCase
{
    private SignedIdempotencyEnvelope $envelope;

    protected function setUp(): void
    {
        $this->envelope = new SignedIdempotencyEnvelope(
            MasterKey::fromHex(sodium_bin2hex(random_bytes(32))),
        );
    }

    #[Test]
    public function sealAndOpenRoundtripsPayload(): void
    {
        $payload = '{"intent_id":"int_123","amount":1500}';

        $sealed = $this->envelope->seal('idem-key-1', $payload);

        self::assertNotSame($payload, $sealed);
        self::assertSame($payload, $this->envelope->open('idem-key-1', $sealed));
    }

    #[Test]
    public function sealedPayloadIsJsonEnvelope(): void
    {
        $sealed = $this->envelope->seal('idem-key-2', 'raw-payload');

        $decoded = json_decode($sealed, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('v', $decoded);
        self::assertArrayHasKey('kid', $decoded);
        self::assertArrayHasKey('h', $decoded);
        self::assertArrayHasKey('p', $decoded);
        self::assertSame(1, $decoded['v']);
    }

    #[Test]
    public function openRejectsTamperedPayload(): void
    {
        $sealed = $this->envelope->seal('idem-key-3', '{"v":1}');

        $envelope = json_decode($sealed, true);
        self::assertIsArray($envelope);
        // Flip a byte in the encoded payload — signature must no longer match.
        $envelope['p'] = base64_encode('forged');
        $tampered = json_encode($envelope);
        self::assertNotFalse($tampered);

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessageIsOrContains('signature mismatch');

        (void) $this->envelope->open('idem-key-3', $tampered);
    }

    #[Test]
    public function openRejectsEnvelopeStolenFromAnotherKey(): void
    {
        // Cross-key replay: a sealed result for key A must not verify when
        // presented as the sealed result for key B, even if the underlying
        // store holds both rows.
        $sealedForA = $this->envelope->seal('idem-key-A', '{"intent":"a"}');

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessageIsOrContains('signature mismatch');

        (void) $this->envelope->open('idem-key-B', $sealedForA);
    }

    #[Test]
    public function openRejectsForgedHmac(): void
    {
        $sealed = $this->envelope->seal('idem-key-4', 'real-payload');

        $envelope = json_decode($sealed, true);
        self::assertIsArray($envelope);
        self::assertIsString($envelope['h']);
        // Flip the HMAC bytes (still right length, wrong value).
        $envelope['h'] = strrev($envelope['h']);
        $tampered = json_encode($envelope);
        self::assertNotFalse($tampered);

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessageIsOrContains('signature mismatch');

        (void) $this->envelope->open('idem-key-4', $tampered);
    }

    #[Test]
    public function openRejectsUnknownSchemaVersion(): void
    {
        $sealed = $this->envelope->seal('idem-key-5', 'payload');
        $envelope = json_decode($sealed, true);
        self::assertIsArray($envelope);
        $envelope['v'] = 999;
        $tampered = json_encode($envelope);
        self::assertNotFalse($tampered);

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessageIsOrContains('schema version');

        (void) $this->envelope->open('idem-key-5', $tampered);
    }

    #[Test]
    public function openRejectsForgedKid(): void
    {
        $sealed = $this->envelope->seal('idem-key-6', 'payload');
        $envelope = json_decode($sealed, true);
        self::assertIsArray($envelope);
        // Replace kid with another 16-hex-char string — kid binding fails.
        $envelope['kid'] = '0123456789abcdef';
        $tampered = json_encode($envelope);
        self::assertNotFalse($tampered);

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessageIsOrContains('key id mismatch');

        (void) $this->envelope->open('idem-key-6', $tampered);
    }

    #[Test]
    public function openRejectsMalformedEnvelope(): void
    {
        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessageIsOrContains('not a JSON object');

        (void) $this->envelope->open('idem-key-7', '"just-a-string"');
    }

    #[Test]
    public function openRejectsEnvelopeSignedWithDifferentMasterKey(): void
    {
        $other = new SignedIdempotencyEnvelope(
            MasterKey::fromHex(sodium_bin2hex(random_bytes(32))),
        );

        $sealedByOther = $other->seal('idem-key-8', 'payload');

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessageIsOrContains('key id mismatch');

        (void) $this->envelope->open('idem-key-8', $sealedByOther);
    }

    #[Test]
    public function openRejectsBinaryPayloadWhenBase64IsCorrupt(): void
    {
        $sealed = $this->envelope->seal('idem-key-9', 'payload');
        $envelope = json_decode($sealed, true);
        self::assertIsArray($envelope);
        $envelope['p'] = '!!!not-valid-base64!!!';
        $tampered = json_encode($envelope);
        self::assertNotFalse($tampered);

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessageIsOrContains('not valid base64');

        (void) $this->envelope->open('idem-key-9', $tampered);
    }

    #[Test]
    public function sealedPayloadEmbedsBase64EncodedOriginal(): void
    {
        // Sanity: the envelope must round-trip the original payload byte-for-byte.
        $payload = "binary\0bytes\nwith\rcontrol";
        $sealed = $this->envelope->seal('idem-key-10', $payload);

        $envelope = json_decode($sealed, true);
        self::assertIsArray($envelope);
        self::assertIsString($envelope['p']);
        self::assertSame($payload, base64_decode($envelope['p'], true));
    }
}
