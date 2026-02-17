<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Webhook;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Payments\Config\PayPalConfig;
use Pulsar\Extension\Payments\Contracts\SubscriptionRepositoryInterface;
use Pulsar\Extension\Payments\Internal\Webhook\PayPalWebhookHandler;

use function crc32;
use function hash_hmac;
use function sprintf;

final class PayPalWebhookHandlerTest extends TestCase
{
    private SubscriptionRepositoryInterface&Stub $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(SubscriptionRepositoryInterface::class);
    }

    #[Test]
    public function rejectsWebhookWhenWebhookIdIsEmpty(): void
    {
        $handler = $this->buildHandler(webhookId: '');

        $result = $handler->handle(
            ['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED'],
            '{}',
            $this->buildHeaders('{}', ''),
        );

        self::assertFalse($result['verified']);
        self::assertSame('', $result['event_type']);
    }

    #[Test]
    public function rejectsWebhookWhenTransmissionIdMissing(): void
    {
        $handler = $this->buildHandler();

        $result = $handler->handle(
            ['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED'],
            '{}',
            [
                'PAYPAL-TRANSMISSION-ID' => '',
                'PAYPAL-TRANSMISSION-TIME' => '2026-01-01T00:00:00Z',
                'PAYPAL-TRANSMISSION-SIG' => 'sig',
                'PAYPAL-CERT-URL' => 'https://api.paypal.com/cert.pem',
            ],
        );

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsWebhookWhenTransmissionTimeMissing(): void
    {
        $handler = $this->buildHandler();

        $result = $handler->handle(
            ['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED'],
            '{}',
            [
                'PAYPAL-TRANSMISSION-ID' => 'tx-1',
                'PAYPAL-TRANSMISSION-TIME' => '',
                'PAYPAL-TRANSMISSION-SIG' => 'sig',
                'PAYPAL-CERT-URL' => 'https://api.paypal.com/cert.pem',
            ],
        );

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsWebhookWhenSignatureMissing(): void
    {
        $handler = $this->buildHandler();

        $result = $handler->handle(
            ['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED'],
            '{}',
            [
                'PAYPAL-TRANSMISSION-ID' => 'tx-1',
                'PAYPAL-TRANSMISSION-TIME' => '2026-01-01T00:00:00Z',
                'PAYPAL-TRANSMISSION-SIG' => '',
                'PAYPAL-CERT-URL' => 'https://api.paypal.com/cert.pem',
            ],
        );

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsWebhookWhenCertUrlMissing(): void
    {
        $handler = $this->buildHandler();

        $result = $handler->handle(
            ['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED'],
            '{}',
            [
                'PAYPAL-TRANSMISSION-ID' => 'tx-1',
                'PAYPAL-TRANSMISSION-TIME' => '2026-01-01T00:00:00Z',
                'PAYPAL-TRANSMISSION-SIG' => 'sig',
                'PAYPAL-CERT-URL' => '',
            ],
        );

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsWebhookWhenCertUrlIsNotHttps(): void
    {
        $handler = $this->buildHandler();

        $result = $handler->handle(
            ['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED'],
            '{}',
            [
                'PAYPAL-TRANSMISSION-ID' => 'tx-1',
                'PAYPAL-TRANSMISSION-TIME' => '2026-01-01T00:00:00Z',
                'PAYPAL-TRANSMISSION-SIG' => 'sig',
                'PAYPAL-CERT-URL' => 'http://api.paypal.com/cert.pem',
            ],
        );

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsWebhookWhenCertUrlIsNotPayPalDomain(): void
    {
        $handler = $this->buildHandler();

        $result = $handler->handle(
            ['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED'],
            '{}',
            [
                'PAYPAL-TRANSMISSION-ID' => 'tx-1',
                'PAYPAL-TRANSMISSION-TIME' => '2026-01-01T00:00:00Z',
                'PAYPAL-TRANSMISSION-SIG' => 'sig',
                'PAYPAL-CERT-URL' => 'https://evil.example.com/cert.pem',
            ],
        );

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsWebhookWithInvalidSignature(): void
    {
        $handler = $this->buildHandler();
        $rawBody = '{"event_type":"BILLING.SUBSCRIPTION.ACTIVATED"}';

        $result = $handler->handle(
            ['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED'],
            $rawBody,
            [
                'PAYPAL-TRANSMISSION-ID' => 'tx-1',
                'PAYPAL-TRANSMISSION-TIME' => '2026-01-01T00:00:00Z',
                'PAYPAL-TRANSMISSION-SIG' => 'invalid-signature',
                'PAYPAL-CERT-URL' => 'https://api.paypal.com/cert.pem',
            ],
        );

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function acceptsWebhookWithValidSignature(): void
    {
        $webhookId = 'WH-test-webhook-id';
        $handler = $this->buildHandler(webhookId: $webhookId);

        $rawBody = '{"event_type":"BILLING.SUBSCRIPTION.ACTIVATED","resource":{"id":"sub-1"}}';
        $headers = $this->buildHeaders($rawBody, $webhookId);

        $result = $handler->handle(
            ['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED', 'resource' => ['id' => 'sub-1']],
            $rawBody,
            $headers,
        );

        self::assertTrue($result['verified']);
        self::assertSame('BILLING.SUBSCRIPTION.ACTIVATED', $result['event_type']);
    }

    #[Test]
    public function acceptsSymantecCertUrl(): void
    {
        $webhookId = 'WH-test-id';
        $handler = $this->buildHandler(webhookId: $webhookId);

        $rawBody = '{}';
        $transmissionId = 'tx-1';
        $transmissionTime = '2026-01-01T00:00:00Z';
        $crc = crc32($rawBody);
        $input = sprintf('%s|%s|%s|%u', $transmissionId, $transmissionTime, $webhookId, $crc);
        $sig = hash_hmac('sha256', $input, $webhookId);

        $result = $handler->handle(
            [],
            $rawBody,
            [
                'PAYPAL-TRANSMISSION-ID' => $transmissionId,
                'PAYPAL-TRANSMISSION-TIME' => $transmissionTime,
                'PAYPAL-TRANSMISSION-SIG' => $sig,
                'PAYPAL-CERT-URL' => 'https://certs.symantec.com/cert.pem',
            ],
        );

        self::assertTrue($result['verified']);
    }

    #[Test]
    public function acceptsVerisignCertUrl(): void
    {
        $webhookId = 'WH-test-id';
        $handler = $this->buildHandler(webhookId: $webhookId);

        $rawBody = '{}';
        $headers = $this->buildHeaders($rawBody, $webhookId, 'https://certs.verisign.com/cert.pem');

        $result = $handler->handle([], $rawBody, $headers);

        self::assertTrue($result['verified']);
    }

    #[Test]
    public function rejectsWebhookWithNoHeaders(): void
    {
        $handler = $this->buildHandler();

        $result = $handler->handle(
            ['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED'],
            '{}',
            [],
        );

        self::assertFalse($result['verified']);
    }

    /**
     * Build valid PayPal transmission headers for the given body and webhook ID.
     *
     * @return array<string, string>
     */
    private function buildHeaders(
        string $rawBody,
        string $webhookId,
        string $certUrl = 'https://api.paypal.com/cert.pem',
    ): array {
        $transmissionId = 'tx-' . bin2hex(random_bytes(8));
        $transmissionTime = '2026-01-01T00:00:00Z';
        $crc = crc32($rawBody);
        $input = sprintf('%s|%s|%s|%u', $transmissionId, $transmissionTime, $webhookId, $crc);
        $sig = hash_hmac('sha256', $input, $webhookId);

        return [
            'PAYPAL-TRANSMISSION-ID' => $transmissionId,
            'PAYPAL-TRANSMISSION-TIME' => $transmissionTime,
            'PAYPAL-TRANSMISSION-SIG' => $sig,
            'PAYPAL-CERT-URL' => $certUrl,
        ];
    }

    private function buildHandler(string $webhookId = 'WH-default-webhook-id'): PayPalWebhookHandler
    {
        $config = new PayPalConfig(
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            webhookId: $webhookId,
            sandbox: true,
        );

        return new PayPalWebhookHandler(
            $this->repository,
            $config,
            new NullLogger(),
        );
    }
}
