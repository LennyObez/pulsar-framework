<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Webhook;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\FixedClock;
use Pulsar\Extension\Payments\Internal\Infrastructure\Webhook\HmacWebhookVerifier;
use Pulsar\Webhook\Exception\WebhookException;

use function sprintf;

#[CoversClass(HmacWebhookVerifier::class)]
final class HmacWebhookVerifierTest extends TestCase
{
    private const string SECRET = 'whsec_test_secret';
    private FixedClock $clock;
    private HmacWebhookVerifier $verifier;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('@1700000000'));
        $this->verifier = new HmacWebhookVerifier($this->clock);
    }

    #[Test]
    public function validSignaturePasses(): void
    {
        $this->expectNotToPerformAssertions();

        $payload = '{"id":"evt_1","type":"payment_intent.created"}';
        $timestamp = 1700000000;
        $signature = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $signature);

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function invalidSignatureThrows(): void
    {
        // F25.8: 64 hex chars (well-formed) but does not match the
        // computed HMAC — exercises the hash_equals mismatch path.
        $payload = '{"id":"evt_1"}';
        $timestamp = 1700000000;
        $header = sprintf('t=%d,v1=%s', $timestamp, str_repeat('0', 64));

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('signature verification failed');

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function nonHexV1SignatureRejected(): void
    {
        // F25.8: payments-extension verifier mirrors the framework
        // verifier — reject anything not 64 lowercase hex chars
        // before hash_equals.
        $timestamp = 1700000000;
        $header = sprintf('t=%d,v1=%s', $timestamp, 'invalid_hex_signature');

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('non-hex v1 signature');

        $this->verifier->verify('{}', $header, self::SECRET, 300);
    }

    #[Test]
    public function expiredTimestampThrows(): void
    {
        $payload = '{"id":"evt_1"}';
        $timestamp = 1700000000 - 600; // 10 minutes old
        $signature = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $signature);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('too old');

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function malformedHeaderEmptyThrows(): void
    {
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('empty header');

        $this->verifier->verify('body', '', self::SECRET, 300);
    }

    #[Test]
    public function malformedHeaderMissingTimestampThrows(): void
    {
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('missing timestamp');

        $this->verifier->verify('body', 'v1=abc123', self::SECRET, 300);
    }

    #[Test]
    public function malformedHeaderNoSignaturesThrows(): void
    {
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('no v1 signatures');

        $this->verifier->verify('body', 't=1700000000', self::SECRET, 300);
    }

    #[Test]
    public function multipleV1OneValidAccepts(): void
    {
        // F25.8: previous fixture used 'old_invalid_sig' which is
        // now rejected at parse-time. Use a well-formed but-
        // non-matching hex sig to keep the secret-rotation
        // scenario exercised.
        $this->expectNotToPerformAssertions();

        $payload = '{"id":"evt_multi"}';
        $timestamp = 1700000000;
        $validSig = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s,v1=%s', $timestamp, str_repeat('a', 64), $validSig);

        // Should not throw — one valid v1 is enough (secret rotation)
        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function allV1InvalidRejects(): void
    {
        // F25.8: keep the test exercising the hash_equals mismatch
        // path — both signatures are well-formed but neither
        // matches the computed HMAC.
        $payload = '{"id":"evt_invalid"}';
        $timestamp = 1700000000;
        $header = sprintf('t=%d,v1=%s,v1=%s', $timestamp, str_repeat('0', 64), str_repeat('1', 64));

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('signature verification failed');

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function withinTolerancePasses(): void
    {
        $this->expectNotToPerformAssertions();

        $payload = '{"id":"evt_tolerance"}';
        $timestamp = 1700000000 - 299; // Just within 300s tolerance
        $signature = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $signature);

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    private function computeSignature(string $payload, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }
}
