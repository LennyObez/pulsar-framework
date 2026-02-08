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

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function invalidSignatureThrows(): void
    {
        $payload = '{"id":"evt_1"}';
        $timestamp = 1700000000;
        $header = sprintf('t=%d,v1=%s', $timestamp, 'invalid_hex_signature');

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('signature verification failed');

        $this->verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function expiredTimestampThrows(): void
    {
        $payload = '{"id":"evt_1"}';
        $timestamp = 1700000000 - 600;
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
        $payload = '{"id":"evt_multi"}';
        $timestamp = 1700000000;
        $validSig = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s,v1=%s', $timestamp, 'old_invalid_sig', $validSig);

        $this->verifier->verify($payload, $header, self::SECRET, 300);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function withinTolerancePasses(): void
    {
        $payload = '{"id":"evt_tolerance"}';
        $timestamp = 1700000000 - 299;
        $signature = $this->computeSignature($payload, $timestamp, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $signature);

        $this->verifier->verify($payload, $header, self::SECRET, 300);

        $this->addToAssertionCount(1);
    }

    private function computeSignature(string $payload, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }
}
