<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Webhook;

use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Payments\Config\PayPalConfig;
use Pulsar\Extension\Payments\Contracts\PayPalCertificateProviderInterface;
use Pulsar\Extension\Payments\Contracts\SubscriptionRepositoryInterface;
use Pulsar\Extension\Payments\Internal\Webhook\PayPalWebhookHandler;

use function base64_encode;
use function bin2hex;
use function crc32;
use function hash_hmac;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_sign;
use function random_bytes;
use function sprintf;

use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_RSA;

final class PayPalWebhookHandlerTest extends TestCase
{
    private SubscriptionRepositoryInterface&Stub $repository;
    private OpenSSLAsymmetricKey $privateKey;
    private string $publicKeyPem;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(SubscriptionRepositoryInterface::class);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $this->privateKey = $key;

        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        /** @var string $pem */
        $pem = $details['key'];
        $this->publicKeyPem = $pem;
    }

    #[Test]
    public function acceptsAWebhookSignedWithTheRealRsaKey(): void
    {
        $webhookId = 'WH-test-webhook-id';
        $rawBody = '{"event_type":"BILLING.SUBSCRIPTION.ACTIVATED","resource":{"id":"sub-1"}}';
        $handler = $this->buildHandler($webhookId, $this->publicKeyPem);

        $result = $handler->handle(
            ['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED', 'resource' => ['id' => 'sub-1']],
            $rawBody,
            $this->rsaSignedHeaders($rawBody, $webhookId),
        );

        self::assertTrue($result['verified']);
        self::assertSame('BILLING.SUBSCRIPTION.ACTIVATED', $result['event_type']);
    }

    #[Test]
    public function rejectsTheOldForgeableHmacSignature(): void
    {
        // An HMAC keyed by the webhookId must never verify: the webhookId is a
        // public identifier, so anyone holding it could forge this signature.
        $webhookId = 'WH-test-webhook-id';
        $rawBody = '{"event_type":"BILLING.SUBSCRIPTION.ACTIVATED","resource":{"id":"sub-1"}}';
        $handler = $this->buildHandler($webhookId, $this->publicKeyPem);

        $transmissionId = 'tx-1';
        $transmissionTime = '2026-01-01T00:00:00Z';
        $message = sprintf('%s|%s|%s|%u', $transmissionId, $transmissionTime, $webhookId, crc32($rawBody));
        $forgedSig = hash_hmac('sha256', $message, $webhookId);

        $result = $handler->handle(
            ['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED', 'resource' => ['id' => 'sub-1']],
            $rawBody,
            [
                'PAYPAL-TRANSMISSION-ID' => $transmissionId,
                'PAYPAL-TRANSMISSION-TIME' => $transmissionTime,
                'PAYPAL-TRANSMISSION-SIG' => base64_encode($forgedSig),
                'PAYPAL-CERT-URL' => 'https://api.paypal.com/cert.pem',
            ],
        );

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsAValidSignatureOverATamperedBody(): void
    {
        // Sign the original body, then deliver a different one: crc32 differs, so
        // the RSA verification over transmissionId|time|webhookId|crc32(body) fails.
        $webhookId = 'WH-test-webhook-id';
        $handler = $this->buildHandler($webhookId, $this->publicKeyPem);

        $headers = $this->rsaSignedHeaders('{"amount":1}', $webhookId);

        $result = $handler->handle([], '{"amount":9999}', $headers);

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsASignatureFromADifferentKey(): void
    {
        $webhookId = 'WH-test-webhook-id';
        $rawBody = '{}';
        // The handler is given a DIFFERENT public key than the one that signed.
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $other);
        $otherDetails = openssl_pkey_get_details($other);
        self::assertIsArray($otherDetails);
        /** @var string $otherPem */
        $otherPem = $otherDetails['key'];

        $handler = $this->buildHandler($webhookId, $otherPem);

        $result = $handler->handle([], $rawBody, $this->rsaSignedHeaders($rawBody, $webhookId));

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsWhenTheCertificateProviderReturnsNull(): void
    {
        // A non-allow-listed cert URL (or a failed fetch) surfaces as null.
        $webhookId = 'WH-test-webhook-id';
        $rawBody = '{}';
        $handler = $this->buildHandler($webhookId, null);

        $result = $handler->handle([], $rawBody, $this->rsaSignedHeaders($rawBody, $webhookId, 'https://evil.example.com/cert.pem'));

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsWebhookWhenWebhookIdIsEmpty(): void
    {
        $handler = $this->buildHandler('', $this->publicKeyPem);

        $result = $handler->handle(
            ['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED'],
            '{}',
            $this->rsaSignedHeaders('{}', ''),
        );

        self::assertFalse($result['verified']);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function incompleteHeaders(): iterable
    {
        $base = [
            'PAYPAL-TRANSMISSION-ID' => 'tx-1',
            'PAYPAL-TRANSMISSION-TIME' => '2026-01-01T00:00:00Z',
            'PAYPAL-TRANSMISSION-SIG' => 'sig',
            'PAYPAL-CERT-URL' => 'https://api.paypal.com/cert.pem',
        ];

        yield 'no headers' => [[]];
        yield 'missing id' => [['PAYPAL-TRANSMISSION-ID' => ''] + $base];
        yield 'missing time' => [['PAYPAL-TRANSMISSION-TIME' => ''] + $base];
        yield 'missing sig' => [['PAYPAL-TRANSMISSION-SIG' => ''] + $base];
        yield 'missing cert url' => [['PAYPAL-CERT-URL' => ''] + $base];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('incompleteHeaders')]
    public function rejectsWebhookWithIncompleteHeaders(array $headers): void
    {
        $handler = $this->buildHandler('WH-test-webhook-id', $this->publicKeyPem);

        $result = $handler->handle(['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED'], '{}', $headers);

        self::assertFalse($result['verified']);
    }

    /**
     * Build PayPal transmission headers with a genuine RSA-SHA256 signature over
     * the message PayPal signs: transmissionId|time|webhookId|crc32(rawBody).
     *
     * @return array<string, string>
     */
    private function rsaSignedHeaders(
        string $rawBody,
        string $webhookId,
        string $certUrl = 'https://api.paypal.com/cert.pem',
    ): array {
        $transmissionId = 'tx-' . bin2hex(random_bytes(8));
        $transmissionTime = '2026-01-01T00:00:00Z';
        $message = sprintf('%s|%s|%s|%u', $transmissionId, $transmissionTime, $webhookId, crc32($rawBody));

        $signature = '';
        openssl_sign($message, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return [
            'PAYPAL-TRANSMISSION-ID' => $transmissionId,
            'PAYPAL-TRANSMISSION-TIME' => $transmissionTime,
            'PAYPAL-TRANSMISSION-SIG' => base64_encode($signature),
            'PAYPAL-CERT-URL' => $certUrl,
        ];
    }

    private function buildHandler(string $webhookId, ?string $providerReturns): PayPalWebhookHandler
    {
        $config = new PayPalConfig(
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            webhookId: $webhookId,
            sandbox: true,
        );

        $provider = $this->createStub(PayPalCertificateProviderInterface::class);
        $provider->method('publicKeyPemFor')->willReturn($providerReturns);

        return new PayPalWebhookHandler($this->repository, $config, new NullLogger(), $provider);
    }
}
