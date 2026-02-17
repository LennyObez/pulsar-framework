<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Webhook;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Webhook\Exception\WebhookException;
use Pulsar\Webhook\HmacWebhookVerifier;

use function hash_hmac;
use function sprintf;

#[CoversClass(HmacWebhookVerifier::class)]
final class HmacWebhookVerifierExtendedTest extends TestCase
{
    private const string SECRET = 'whsec_test_secret_key';

    #[Test]
    public function verifyAcceptsValidSignature(): void
    {
        $now = new DateTimeImmutable('@1700000000');
        $verifier = new HmacWebhookVerifier($now);

        $payload = '{"event":"test"}';
        $timestamp = $now->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $timestamp, $signature);

        // Should not throw
        $verifier->verify($payload, $header, self::SECRET, 300);

        // If we get here, verification passed
        self::assertTrue(true, 'Verification should succeed for valid signature');
    }

    #[Test]
    public function verifyRejectsExpiredTimestamp(): void
    {
        $now = new DateTimeImmutable('@1700001000');
        $verifier = new HmacWebhookVerifier($now);

        $payload = '{}';
        $oldTimestamp = 1700000000; // 1000 seconds ago
        $signature = hash_hmac('sha256', $oldTimestamp . '.' . $payload, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $oldTimestamp, $signature);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('too old');

        $verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function verifyRejectsWrongSignature(): void
    {
        $now = new DateTimeImmutable('@1700000000');
        $verifier = new HmacWebhookVerifier($now);

        $payload = '{"test":1}';
        $header = sprintf('t=%d,v1=%s', $now->getTimestamp(), 'deadbeef1234567890');

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('signature');

        $verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function verifyRejectsEmptyHeader(): void
    {
        $verifier = new HmacWebhookVerifier();

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('empty header');

        $verifier->verify('payload', '', self::SECRET, 300);
    }

    #[Test]
    public function verifyRejectsMissingTimestamp(): void
    {
        $verifier = new HmacWebhookVerifier();

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('missing timestamp');

        $verifier->verify('payload', 'v1=abc123', self::SECRET, 300);
    }

    #[Test]
    public function verifyRejectsMissingSignatures(): void
    {
        $verifier = new HmacWebhookVerifier();

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('no v1 signatures');

        $verifier->verify('payload', 't=1700000000', self::SECRET, 300);
    }

    #[Test]
    public function verifyAcceptsMultipleV1SignaturesWithRotation(): void
    {
        $now = new DateTimeImmutable('@1700000000');
        $verifier = new HmacWebhookVerifier($now);

        $payload = '{"ok":true}';
        $timestamp = $now->getTimestamp();
        $wrongSig = 'wrong_signature_here';
        $correctSig = hash_hmac('sha256', $timestamp . '.' . $payload, self::SECRET);
        $header = sprintf('t=%d,v1=%s,v1=%s', $timestamp, $wrongSig, $correctSig);

        // Should succeed because at least one v1 matches
        $verifier->verify($payload, $header, self::SECRET, 300);

        self::assertTrue(true, 'Verification should succeed when any v1 signature matches');
    }

    #[Test]
    #[DataProvider('malformedHeaders')]
    public function verifyRejectsMalformedHeaders(string $header, string $expectedMessagePart): void
    {
        $verifier = new HmacWebhookVerifier(new DateTimeImmutable('@1700000000'));

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage($expectedMessagePart);

        $verifier->verify('payload', $header, self::SECRET, 300);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedHeaders(): iterable
    {
        yield 'empty' => ['', 'empty header'];
        yield 'no timestamp' => ['v1=abc', 'missing timestamp'];
        yield 'no signatures' => ['t=1700000000', 'no v1 signatures'];
        yield 'invalid timestamp' => ['t=notanumber,v1=abc', 'invalid timestamp'];
    }
}
