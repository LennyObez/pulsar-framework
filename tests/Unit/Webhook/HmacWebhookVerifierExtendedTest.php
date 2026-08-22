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

        $this->expectNotToPerformAssertions();

        // verify() throws on failure; reaching the end of the test means
        // a valid signature was accepted.
        $verifier->verify($payload, $header, self::SECRET, 300);
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
        $this->expectExceptionMessageIsOrContains('too old');

        $verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function verifyRejectsWrongSignature(): void
    {
        // A well-formed hex (64 chars) that simply does not match the
        // computed HMAC. A shorter or non-hex fixture is rejected by the
        // format check before hash_equals, which is not the path under
        // test here.
        $now = new DateTimeImmutable('@1700000000');
        $verifier = new HmacWebhookVerifier($now);

        $payload = '{"test":1}';
        $header = sprintf('t=%d,v1=%s', $now->getTimestamp(), str_repeat('0', 64));

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('signature');

        $verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    public function verifyRejectsEmptyHeader(): void
    {
        $verifier = new HmacWebhookVerifier();

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('empty header');

        $verifier->verify('payload', '', self::SECRET, 300);
    }

    #[Test]
    public function verifyRejectsMissingTimestamp(): void
    {
        $verifier = new HmacWebhookVerifier();

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('missing timestamp');

        $verifier->verify('payload', 'v1=abc123', self::SECRET, 300);
    }

    #[Test]
    public function verifyRejectsMissingSignatures(): void
    {
        $verifier = new HmacWebhookVerifier();

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains('no v1 signatures');

        $verifier->verify('payload', 't=1700000000', self::SECRET, 300);
    }

    #[Test]
    public function verifyAcceptsMultipleV1SignaturesWithRotation(): void
    {
        // The rotated-out candidate must be well-formed hex: a malformed
        // one is rejected at parse time, which would short-circuit the
        // multi-signature acceptance path this test covers.
        $now = new DateTimeImmutable('@1700000000');
        $verifier = new HmacWebhookVerifier($now);

        $payload = '{"ok":true}';
        $timestamp = $now->getTimestamp();
        $wrongSig = str_repeat('0', 64);
        $correctSig = hash_hmac('sha256', $timestamp . '.' . $payload, self::SECRET);
        $header = sprintf('t=%d,v1=%s,v1=%s', $timestamp, $wrongSig, $correctSig);

        $this->expectNotToPerformAssertions();

        // verify() iterates v1 signatures and accepts on the first match;
        // reaching the end of the test means the second v1 (correct one)
        // matched, validating the multi-signature acceptance path.
        $verifier->verify($payload, $header, self::SECRET, 300);
    }

    #[Test]
    #[DataProvider('malformedHeaders')]
    public function verifyRejectsMalformedHeaders(string $header, string $expectedMessagePart): void
    {
        $verifier = new HmacWebhookVerifier(new DateTimeImmutable('@1700000000'));

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessagePart);

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
