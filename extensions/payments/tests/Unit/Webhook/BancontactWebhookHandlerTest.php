<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Webhook;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Payments\Config\StripeConfig;
use Pulsar\Extension\Payments\Internal\Webhook\BancontactWebhookHandler;

use function hash_hmac;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

final class BancontactWebhookHandlerTest extends TestCase
{
    private BancontactWebhookHandler $handler;
    private string $webhookSecret;

    protected function setUp(): void
    {
        $this->webhookSecret = 'whsec_test_bancontact';

        $stripeConfig = StripeConfig::fromArray([
            'webhook_secret' => $this->webhookSecret,
            'secret_key' => 'sk_test',
        ]);

        $this->handler = new BancontactWebhookHandler(
            $stripeConfig,
            new NullLogger(),
        );
    }

    #[Test]
    public function handleVerifiesValidStripeSignature(): void
    {
        $payload = json_encode([
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_123',
                    'payment_method_types' => ['bancontact'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = $this->buildStripeSignature($payload);

        $result = $this->handler->handle($payload, $signature);

        self::assertTrue($result['verified']);
        self::assertSame('payment_intent.succeeded', $result['event_type']);
        self::assertTrue($result['processed']);
    }

    #[Test]
    public function handleRejectsInvalidSignature(): void
    {
        $payload = json_encode(['type' => 'test'], JSON_THROW_ON_ERROR);

        $result = $this->handler->handle($payload, 'invalid');

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function handleIgnoresNonBancontactEvents(): void
    {
        $payload = json_encode([
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_456',
                    'payment_method_types' => ['card'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = $this->buildStripeSignature($payload);

        $result = $this->handler->handle($payload, $signature);

        self::assertTrue($result['verified']);
        self::assertFalse($result['processed']);
    }

    #[Test]
    public function handleProcessesPaymentFailed(): void
    {
        $payload = json_encode([
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'pi_789',
                    'payment_method_types' => ['bancontact'],
                    'last_payment_error' => ['message' => 'Insufficient funds'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = $this->buildStripeSignature($payload);

        $result = $this->handler->handle($payload, $signature);

        self::assertTrue($result['verified']);
        self::assertTrue($result['processed']);
    }

    #[Test]
    public function handleProcessesRefunded(): void
    {
        $payload = json_encode([
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => 'ch_refund_1',
                    'payment_method_types' => ['bancontact'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = $this->buildStripeSignature($payload);

        $result = $this->handler->handle($payload, $signature);

        self::assertTrue($result['verified']);
        self::assertTrue($result['processed']);
    }

    #[Test]
    public function handleReturnsFalseForUnknownEventType(): void
    {
        $payload = json_encode([
            'type' => 'customer.updated',
            'data' => [
                'object' => [
                    'id' => 'cus_1',
                    'payment_method_types' => ['bancontact'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = $this->buildStripeSignature($payload);

        $result = $this->handler->handle($payload, $signature);

        self::assertTrue($result['verified']);
        self::assertFalse($result['processed']);
    }

    #[Test]
    public function handleRejectsEmptyWebhookSecret(): void
    {
        $handler = new BancontactWebhookHandler(
            StripeConfig::fromArray([]),
            new NullLogger(),
        );

        $payload = json_encode(['type' => 'test'], JSON_THROW_ON_ERROR);
        $result = $handler->handle($payload, 't=123,v1=abc');

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function handleRejectsSignatureWithoutTimestamp(): void
    {
        $payload = json_encode(['type' => 'test'], JSON_THROW_ON_ERROR);

        $result = $this->handler->handle($payload, 'v1=abc123');

        self::assertFalse($result['verified']);
    }

    private function buildStripeSignature(string $payload): string
    {
        $timestamp = (string) time();
        $signedPayload = "$timestamp.$payload";
        $signature = hash_hmac('sha256', $signedPayload, $this->webhookSecret);

        return "t=$timestamp,v1=$signature";
    }
}
