<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Webhook;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Webhook\Exception\WebhookException;
use Pulsar\Webhook\HmacWebhookVerifier;

use function sprintf;

#[CoversClass(HmacWebhookVerifier::class)]
final class HmacWebhookVerifierTest extends TestCase
{
    private const string SECRET = 'whsec_test_secret';
    private HmacWebhookVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new HmacWebhookVerifier(new DateTimeImmutable('@1700000000'));
    }

    #[Test]
    public function validSignaturePasses(): void
    {
        $payload = '{"id":"evt_1","type":"payment_intent.created"}';
        $timestamp = 1700000000;
        $signature = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $signature);

        $this->verifier->verify($payload, $header, self::SECRET, 300);

        // Verify completed without throwing — verifier is still intact
        self::assertInstanceOf(HmacWebhookVerifier::class, $this->verifier);
    }

    #[Test]
    public function invalidSignatureThrows(): void
    {
        // F25.8: use a well-formed but non-matching signature
        // (64 hex chars, all zeros) so we exercise the
        // hash_equals mismatch path rather than the format-rejection
        // path.
        $payload = '{"id":"evt_1"}';
        $timestamp = 1700000000;
        $header = sprintf('t=%d,v1=%s', $timestamp, str_repeat('0', 64));

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('signature verification failed');

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function nonHexV1SignatureRejected(): void
    {
        // F25.8: anything that isn't 64 lowercase hex chars in v1=
        // must surface as malformedHeader BEFORE hash_equals so the
        // verification path never compares against attacker-supplied
        // bytes of arbitrary length / charset.
        $timestamp = 1700000000;
        $header = sprintf('t=%d,v1=%s', $timestamp, 'invalid_hex_signature');

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('non-hex v1 signature');

        $this->verifier->verify('{}', $header, self::SECRET, 300);
    }

    #[Test]
    public function shortV1SignatureRejected(): void
    {
        $timestamp = 1700000000;
        $header = sprintf('t=%d,v1=%s', $timestamp, 'deadbeef'); // 8 hex chars

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('non-hex v1 signature');

        $this->verifier->verify('{}', $header, self::SECRET, 300);
    }

    #[Test]
    public function expiredTimestampThrows(): void
    {
        $payload = '{"id":"evt_1"}';
        $timestamp = 1700000000 - 600;
        $signature = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $signature);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('too old');

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function malformedHeaderEmptyThrows(): void
    {
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('empty header');

        $this->verifier->verify('body', '', self::SECRET, 300);
    }

    #[Test]
    public function malformedHeaderMissingTimestampThrows(): void
    {
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('missing timestamp');

        $this->verifier->verify('body', 'v1=abc123', self::SECRET, 300);
    }

    #[Test]
    public function malformedHeaderNoSignaturesThrows(): void
    {
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('no v1 signatures');

        $this->verifier->verify('body', 't=1700000000', self::SECRET, 300);
    }

    #[Test]
    public function multipleV1OneValidAccepts(): void
    {
        // F25.8: 'old_invalid_sig' (15 chars, has '_') would now be
        // rejected at parse time before hash_equals ever sees it.
        // Use an old-but-well-formed hex signature so we still
        // exercise the multi-signature acceptance branch.
        $payload = '{"id":"evt_multi"}';
        $timestamp = 1700000000;
        $validSig = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s,v1=%s', $timestamp, str_repeat('a', 64), $validSig);

        $this->verifier->verify($payload, $header, self::SECRET, 300);

        // Multiple v1 entries accepted — at least one valid signature is sufficient
        self::assertInstanceOf(HmacWebhookVerifier::class, $this->verifier);
    }

    #[Test]
    public function withinTolerancePasses(): void
    {
        $payload = '{"id":"evt_tolerance"}';
        $timestamp = 1700000000 - 299;
        $signature = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $signature);

        $this->verifier->verify($payload, $header, self::SECRET, 300);

        // 299s old with 300s tolerance — should pass without throwing
        self::assertInstanceOf(HmacWebhookVerifier::class, $this->verifier);
    }

    #[Test]
    public function overlongNumericTimestampRejected(): void
    {
        // F25.8: a 13+ digit numeric timestamp passes ctype_digit but
        // saturates the (int) cast on 64-bit, producing a nonsensical age.
        // It must be rejected as a malformed timestamp, not silently coerced.
        $header = sprintf('t=%s,v1=%s', str_repeat('9', 20), str_repeat('a', 64));

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('invalid timestamp');

        $this->verifier->verify('{}', $header, self::SECRET, 300);
    }

    #[Test]
    public function tooManyV1SignaturesRejected(): void
    {
        // F25.8: each v1= entry forces one HMAC computation. An unbounded
        // header is a CPU-amplification vector, so the parser caps the
        // candidate count and rejects before any HMAC work.
        $timestamp = 1700000000;
        $header = sprintf('t=%d', $timestamp);
        for ($i = 0; $i < 6; $i++) {
            $header .= sprintf(',v1=%s', str_repeat('a', 64));
        }

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('too many v1 signatures');

        $this->verifier->verify('{}', $header, self::SECRET, 300);
    }

    private function computeSignature(string $payload, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }
}
