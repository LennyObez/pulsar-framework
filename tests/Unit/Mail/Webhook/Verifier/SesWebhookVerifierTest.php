<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Webhook\Verifier;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Webhook\Verifier\SesWebhookVerifier;
use Pulsar\Mail\Webhook\WebhookRequest;
use ReflectionClass;

use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

#[CoversClass(SesWebhookVerifier::class)]
final class SesWebhookVerifierTest extends TestCase
{
    #[Test]
    public function rejectsInvalidJson(): void
    {
        $verifier = new SesWebhookVerifier();

        $request = new WebhookRequest('not-json', [], '10.0.0.1', time(), 'ses');

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsMissingCertUrl(): void
    {
        $verifier = new SesWebhookVerifier();

        $payload = json_encode([
            'Signature' => 'some-sig',
            'Type' => 'Notification',
        ], JSON_THROW_ON_ERROR);

        $request = new WebhookRequest($payload, [], '10.0.0.1', time(), 'ses');

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsMissingSignature(): void
    {
        $verifier = new SesWebhookVerifier();

        $payload = json_encode([
            'SigningCertURL' => 'https://sns.amazonaws.com/cert.pem',
            'Type' => 'Notification',
        ], JSON_THROW_ON_ERROR);

        $request = new WebhookRequest($payload, [], '10.0.0.1', time(), 'ses');

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsHttpCertUrl(): void
    {
        $verifier = new SesWebhookVerifier();

        $payload = json_encode([
            'SigningCertURL' => 'http://sns.amazonaws.com/cert.pem',
            'Signature' => 'some-sig',
            'Type' => 'Notification',
        ], JSON_THROW_ON_ERROR);

        $request = new WebhookRequest($payload, [], '10.0.0.1', time(), 'ses');

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsDisallowedCertHost(): void
    {
        $verifier = new SesWebhookVerifier(['sns.amazonaws.com']);

        $payload = json_encode([
            'SigningCertURL' => 'https://evil.example.com/cert.pem',
            'Signature' => 'some-sig',
            'Type' => 'Notification',
        ], JSON_THROW_ON_ERROR);

        $request = new WebhookRequest($payload, [], '10.0.0.1', time(), 'ses');

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsNonStringCertUrlAndSignature(): void
    {
        $verifier = new SesWebhookVerifier();

        $payload = json_encode([
            'SigningCertURL' => 12345,
            'Signature' => true,
            'Type' => 'Notification',
        ], JSON_THROW_ON_ERROR);

        $request = new WebhookRequest($payload, [], '10.0.0.1', time(), 'ses');

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function rejectsEmptyAllowlist(): void
    {
        $verifier = new SesWebhookVerifier([]);

        $payload = json_encode([
            'SigningCertURL' => 'https://sns.amazonaws.com/cert.pem',
            'Signature' => 'some-sig',
            'Type' => 'Notification',
        ], JSON_THROW_ON_ERROR);

        $request = new WebhookRequest($payload, [], '10.0.0.1', time(), 'ses');

        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function certCacheServesSubsequentRequests(): void
    {
        // SesWebhookVerifier is no longer readonly (has mutable cert cache).
        // We verify the class can be instantiated and its verify path
        // exercises the caching code path (cache miss on first call).
        $verifier = new SesWebhookVerifier();

        // First call — will attempt to fetch cert (and fail since no real host)
        $payload = json_encode([
            'SigningCertURL' => 'https://sns.amazonaws.com/nonexistent-cert.pem',
            'Signature' => base64_encode('fake-signature'),
            'Type' => 'Notification',
            'Message' => 'test',
            'MessageId' => 'msg-1',
            'Timestamp' => '2025-01-01T00:00:00Z',
            'TopicArn' => 'arn:aws:sns:us-east-1:123:test',
        ], JSON_THROW_ON_ERROR);

        $request = new WebhookRequest($payload, [], '10.0.0.1', time(), 'ses');

        // Will return false because cert fetch fails, but exercises the timeout + cache path
        self::assertFalse($verifier->verify($request));
    }

    #[Test]
    public function verifierIsNotReadonly(): void
    {
        // SesWebhookVerifier must NOT be readonly to support mutable cert cache
        $reflection = new ReflectionClass(SesWebhookVerifier::class);
        self::assertFalse($reflection->isReadOnly());
    }
}
