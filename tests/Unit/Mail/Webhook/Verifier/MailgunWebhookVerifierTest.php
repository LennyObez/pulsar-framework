<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Webhook\Verifier;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Webhook\Verifier\MailgunWebhookVerifier;
use Pulsar\Mail\Webhook\WebhookRequest;

use function hash_hmac;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

#[CoversClass(MailgunWebhookVerifier::class)]
final class MailgunWebhookVerifierTest extends TestCase
{
    private const string SIGNING_KEY = 'test-signing-key-abc123';

    #[Test]
    public function verifiesValidSignature(): void
    {
        $verifier = new MailgunWebhookVerifier(self::SIGNING_KEY);

        $timestamp = (string) time();
        $token = 'random-token-abc';
        $signature = hash_hmac('sha256', $timestamp . $token, self::SIGNING_KEY);

        $payload = json_encode([
            'signature' => [
                'timestamp' => $timestamp,
                'token' => $token,
                'signature' => $signature,
            ],
            'event-data' => ['event' => 'delivered'],
        ], JSON_THROW_ON_ERROR);

        $request = new WebhookRequest($payload, [], '10.0.0.1', time(), 'mailgun');

        self::assertTrue($verifier->verify($request));
    }

    #[Test]
    public function rejectsInvalidSignature(): void
    {
        $verifier = new MailgunWebhookVerifier(self::SIGNING_KEY);

        $payload = json_encode([
            'signature' => [
                'timestamp' => (string) time(),
                'token' => 'random-token',
                'signature' => 'invalid-signature-value',
            ],
        ], JSON_THROW_ON_ERROR);

        $request = new WebhookRequest($payload, [], '10.0.0.1', time(), 'mailgun');

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsInvalidJson(): void
    {
        $verifier = new MailgunWebhookVerifier(self::SIGNING_KEY);

        $request = new WebhookRequest('not-json', [], '10.0.0.1', time(), 'mailgun');

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsMissingSignatureBlock(): void
    {
        $verifier = new MailgunWebhookVerifier(self::SIGNING_KEY);

        $payload = json_encode(['event-data' => ['event' => 'delivered']], JSON_THROW_ON_ERROR);
        $request = new WebhookRequest($payload, [], '10.0.0.1', time(), 'mailgun');

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsMissingTimestamp(): void
    {
        $verifier = new MailgunWebhookVerifier(self::SIGNING_KEY);

        $payload = json_encode([
            'signature' => [
                'token' => 'random-token',
                'signature' => 'some-signature',
            ],
        ], JSON_THROW_ON_ERROR);

        $request = new WebhookRequest($payload, [], '10.0.0.1', time(), 'mailgun');

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsMissingToken(): void
    {
        $verifier = new MailgunWebhookVerifier(self::SIGNING_KEY);

        $payload = json_encode([
            'signature' => [
                'timestamp' => (string) time(),
                'signature' => 'some-signature',
            ],
        ], JSON_THROW_ON_ERROR);

        $request = new WebhookRequest($payload, [], '10.0.0.1', time(), 'mailgun');

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsNonStringSignatureFields(): void
    {
        $verifier = new MailgunWebhookVerifier(self::SIGNING_KEY);

        $payload = json_encode([
            'signature' => [
                'timestamp' => 12345,
                'token' => true,
                'signature' => null,
            ],
        ], JSON_THROW_ON_ERROR);

        $request = new WebhookRequest($payload, [], '10.0.0.1', time(), 'mailgun');

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsSignatureFromDifferentKey(): void
    {
        $verifier = new MailgunWebhookVerifier(self::SIGNING_KEY);

        $timestamp = (string) time();
        $token = 'random-token';
        $signature = hash_hmac('sha256', $timestamp . $token, 'different-key');

        $payload = json_encode([
            'signature' => [
                'timestamp' => $timestamp,
                'token' => $token,
                'signature' => $signature,
            ],
        ], JSON_THROW_ON_ERROR);

        $request = new WebhookRequest($payload, [], '10.0.0.1', time(), 'mailgun');

        self::assertFalse($verifier->verify($request));
    }
}
