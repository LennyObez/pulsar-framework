<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Webhook;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Extension\Payments\Internal\Infrastructure\Webhook\HmacWebhookVerifier;
use Pulsar\Webhook\Exception\WebhookException;

final class HmacWebhookVerifierTest extends TestCase
{
    private const string SECRET = 'whsec_test_secret_key';

    #[Test]
    public function verifyAcceptsValidSignature(): void
    {
        $now = new DateTimeImmutable('@1700000000');
        $clock = $this->buildClock($now);
        $verifier = new HmacWebhookVerifier($clock);

        $payload = '{"event":"test"}';
        $timestamp = $now->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, self::SECRET);
        $header = "t={$timestamp},v1={$signature}";

        $verifier->verify($payload, $header, self::SECRET, 300);

        // No exception means success
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function verifyRejectsInvalidSignature(): void
    {
        // 64 hex chars (well-formed) but does not match the
        // computed HMAC — exercises the hash_equals mismatch path
        // rather than the format-rejection path.
        $now = new DateTimeImmutable('@1700000000');
        $verifier = new HmacWebhookVerifier($this->buildClock($now));

        $header = 't=1700000000,v1=' . str_repeat('0', 64);

        $this->expectException(WebhookException::class);
        $verifier->verify('{"event":"test"}', $header, self::SECRET, 300);
    }

    #[Test]
    public function verifyRejectsExpiredTimestamp(): void
    {
        $now = new DateTimeImmutable('@1700001000');
        $verifier = new HmacWebhookVerifier($this->buildClock($now));

        $payload = '{}';
        $oldTs = 1700000000;
        $sig = hash_hmac('sha256', $oldTs . '.' . $payload, self::SECRET);
        $header = "t={$oldTs},v1={$sig}";

        $this->expectException(WebhookException::class);
        $verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function verifyRejectsEmptyHeader(): void
    {
        $verifier = new HmacWebhookVerifier($this->buildClock(new DateTimeImmutable()));

        $this->expectException(WebhookException::class);
        $verifier->verify('{}', '', self::SECRET, 300);
    }

    #[Test]
    public function verifyRejectsMissingTimestamp(): void
    {
        $verifier = new HmacWebhookVerifier($this->buildClock(new DateTimeImmutable()));

        $this->expectException(WebhookException::class);
        $verifier->verify('{}', 'v1=abc123', self::SECRET, 300);
    }

    #[Test]
    public function verifyRejectsMissingSignatures(): void
    {
        $verifier = new HmacWebhookVerifier($this->buildClock(new DateTimeImmutable()));

        $this->expectException(WebhookException::class);
        $verifier->verify('{}', 't=1700000000', self::SECRET, 300);
    }

    #[Test]
    public function verifyAcceptsMultipleV1SignaturesWithOneValid(): void
    {
        // The stale signature must be well-formed hex, or it is rejected
        // at parse-time and never reaches the comparison. Only a valid-
        // shaped but non-matching sig exercises the secret-rotation path.
        $now = new DateTimeImmutable('@1700000000');
        $verifier = new HmacWebhookVerifier($this->buildClock($now));

        $payload = '{"rotated":"true"}';
        $ts = $now->getTimestamp();
        $validSig = hash_hmac('sha256', $ts . '.' . $payload, self::SECRET);
        $oldSig = str_repeat('a', 64);
        $header = "t={$ts},v1={$oldSig},v1={$validSig}";

        $verifier->verify($payload, $header, self::SECRET, 300);

        $this->addToAssertionCount(1);
    }

    private function buildClock(DateTimeImmutable $now): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn($now);

        return $clock;
    }
}
