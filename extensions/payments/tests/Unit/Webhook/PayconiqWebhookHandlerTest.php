<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Payments\Config\PayconiqConfig;
use Pulsar\Extension\Payments\Internal\Webhook\PayconiqWebhookHandler;

use function hash_hmac;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class PayconiqWebhookHandlerTest extends TestCase
{
    private PayconiqWebhookHandler $handler;
    private string $webhookSecret;

    protected function setUp(): void
    {
        $this->webhookSecret = 'test_webhook_secret_123';

        $config = PayconiqConfig::fromArray([
            'merchant_id' => 'merch_test',
            'api_key' => 'key_test',
            'webhook_secret' => $this->webhookSecret,
        ]);

        $this->handler = new PayconiqWebhookHandler(
            $config,
            new NullLogger(),
        );
    }

    #[Test]
    public function handleVerifiesValidSignature(): void
    {
        $payload = json_encode([
            'paymentId' => 'pcq_pay_001',
            'status' => 'SUCCEEDED',
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $payload, $this->webhookSecret);

        $result = $this->handler->handle($payload, $signature);

        self::assertTrue($result['verified']);
        self::assertSame('pcq_pay_001', $result['payment_id']);
        self::assertSame('SUCCEEDED', $result['status']);
        self::assertTrue($result['processed']);
    }

    #[Test]
    public function handleRejectsInvalidSignature(): void
    {
        $payload = json_encode(['paymentId' => 'pcq_001', 'status' => 'SUCCEEDED'], JSON_THROW_ON_ERROR);

        $result = $this->handler->handle($payload, 'invalid_signature');

        self::assertFalse($result['verified']);
        self::assertSame('', $result['payment_id']);
        self::assertFalse($result['processed']);
    }

    #[Test]
    public function handleRejectsEmptyWebhookSecret(): void
    {
        $handler = new PayconiqWebhookHandler(
            PayconiqConfig::fromArray([]),
            new NullLogger(),
        );

        $payload = json_encode(['paymentId' => 'pcq_001'], JSON_THROW_ON_ERROR);
        $result = $handler->handle($payload, 'any');

        self::assertFalse($result['verified']);
    }

    #[Test]
    #[DataProvider('payconiqStatusProvider')]
    public function handleProcessesKnownStatuses(string $status, bool $expectedProcessed): void
    {
        $payload = json_encode([
            'paymentId' => 'pcq_pay_002',
            'status' => $status,
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $payload, $this->webhookSecret);

        $result = $this->handler->handle($payload, $signature);

        self::assertTrue($result['verified']);
        self::assertSame($status, $result['status']);
        self::assertSame($expectedProcessed, $result['processed']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function payconiqStatusProvider(): iterable
    {
        yield 'SUCCEEDED' => ['SUCCEEDED', true];
        yield 'AUTHORIZED' => ['AUTHORIZED', true];
        yield 'IDENTIFIED' => ['IDENTIFIED', true];
        yield 'CANCELLED' => ['CANCELLED', true];
        yield 'FAILED' => ['FAILED', true];
        yield 'EXPIRED' => ['EXPIRED', true];
        yield 'PENDING (unknown)' => ['PENDING', false];
        yield 'RANDOM (unknown)' => ['RANDOM', false];
    }

    #[Test]
    public function handleReturnsUnprocessedForMissingPaymentId(): void
    {
        $payload = json_encode([
            'status' => 'SUCCEEDED',
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $payload, $this->webhookSecret);

        $result = $this->handler->handle($payload, $signature);

        self::assertTrue($result['verified']);
        self::assertSame('', $result['payment_id']);
        self::assertFalse($result['processed']);
    }

    #[Test]
    public function handleReturnsUnprocessedForMissingStatus(): void
    {
        $payload = json_encode([
            'paymentId' => 'pcq_001',
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $payload, $this->webhookSecret);

        $result = $this->handler->handle($payload, $signature);

        self::assertTrue($result['verified']);
        self::assertSame('', $result['status']);
        self::assertFalse($result['processed']);
    }
}
