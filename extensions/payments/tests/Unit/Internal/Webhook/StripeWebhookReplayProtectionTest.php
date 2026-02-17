<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Webhook;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Payments\Config\StripeConfig;
use Pulsar\Extension\Payments\Contracts\SubscriptionRepositoryInterface;
use Pulsar\Extension\Payments\Internal\Webhook\StripeWebhookHandler;

use function hash_hmac;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

final class StripeWebhookReplayProtectionTest extends TestCase
{
    private const string WEBHOOK_SECRET = 'whsec_test_secret_key';

    private StripeWebhookHandler $handler;

    protected function setUp(): void
    {
        $repository = $this->createStub(SubscriptionRepositoryInterface::class);
        $config = new StripeConfig(
            secretKey: 'sk_test',
            publishableKey: 'pk_test',
            webhookSecret: self::WEBHOOK_SECRET,
            apiVersion: '2024-12-18.acacia',
            testMode: true,
        );

        $this->handler = new StripeWebhookHandler(
            $repository,
            $config,
            new NullLogger(),
        );
    }

    #[Test]
    public function acceptsWebhookWithCurrentTimestamp(): void
    {
        $payload = json_encode(['type' => 'customer.subscription.updated', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = $this->computeSignature($payload, $timestamp);

        $result = $this->handler->handle($payload, "t={$timestamp},v1={$signature}");

        self::assertTrue($result['verified']);
    }

    #[Test]
    public function acceptsWebhookWithinToleranceWindow(): void
    {
        $payload = json_encode(['type' => 'customer.subscription.updated', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
        // 4 minutes ago (within 5-minute tolerance)
        $timestamp = (string) (time() - 240);
        $signature = $this->computeSignature($payload, $timestamp);

        $result = $this->handler->handle($payload, "t={$timestamp},v1={$signature}");

        self::assertTrue($result['verified']);
    }

    #[Test]
    public function rejectsWebhookOlderThanToleranceWindow(): void
    {
        $payload = json_encode(['type' => 'customer.subscription.updated', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
        // 6 minutes ago (outside 5-minute tolerance)
        $timestamp = (string) (time() - 360);
        $signature = $this->computeSignature($payload, $timestamp);

        $result = $this->handler->handle($payload, "t={$timestamp},v1={$signature}");

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsWebhookWithFutureTimestampOutsideTolerance(): void
    {
        $payload = json_encode(['type' => 'customer.subscription.updated', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
        // 6 minutes in the future
        $timestamp = (string) (time() + 360);
        $signature = $this->computeSignature($payload, $timestamp);

        $result = $this->handler->handle($payload, "t={$timestamp},v1={$signature}");

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsWebhookWithZeroTimestamp(): void
    {
        $payload = json_encode(['type' => 'customer.subscription.updated', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
        $timestamp = '0';
        $signature = $this->computeSignature($payload, $timestamp);

        $result = $this->handler->handle($payload, "t={$timestamp},v1={$signature}");

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsReplayedWebhookFromDistantPast(): void
    {
        $payload = json_encode(['type' => 'customer.subscription.updated', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
        // 1 hour ago
        $timestamp = (string) (time() - 3600);
        $signature = $this->computeSignature($payload, $timestamp);

        $result = $this->handler->handle($payload, "t={$timestamp},v1={$signature}");

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function acceptsWebhookAtExactToleranceBoundary(): void
    {
        $payload = json_encode(['type' => 'customer.subscription.updated', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
        // Exactly 299 seconds ago (within 300-second tolerance)
        $timestamp = (string) (time() - 299);
        $signature = $this->computeSignature($payload, $timestamp);

        $result = $this->handler->handle($payload, "t={$timestamp},v1={$signature}");

        self::assertTrue($result['verified']);
    }

    #[Test]
    public function rejectsWebhookJustOutsideToleranceBoundary(): void
    {
        $payload = json_encode(['type' => 'customer.subscription.updated', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
        // Exactly 301 seconds ago (outside 300-second tolerance)
        $timestamp = (string) (time() - 301);
        $signature = $this->computeSignature($payload, $timestamp);

        $result = $this->handler->handle($payload, "t={$timestamp},v1={$signature}");

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsWebhookWithEmptySecret(): void
    {
        $repository = $this->createStub(SubscriptionRepositoryInterface::class);
        $config = new StripeConfig(
            secretKey: 'sk_test',
            publishableKey: 'pk_test',
            webhookSecret: '',
            apiVersion: '2024-12-18.acacia',
            testMode: true,
        );

        $handler = new StripeWebhookHandler($repository, $config, new NullLogger());

        $payload = '{"type":"test"}';
        $result = $handler->handle($payload, 't=123,v1=abc');

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsWebhookWithMissingTimestamp(): void
    {
        $payload = '{"type":"test"}';
        $result = $this->handler->handle($payload, 'v1=abc123');

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsWebhookWithMissingSignature(): void
    {
        $payload = '{"type":"test"}';
        $timestamp = (string) time();
        $result = $this->handler->handle($payload, "t={$timestamp}");

        self::assertFalse($result['verified']);
    }

    #[Test]
    public function rejectsWebhookWithInvalidSignature(): void
    {
        $payload = '{"type":"test"}';
        $timestamp = (string) time();
        $result = $this->handler->handle($payload, "t={$timestamp},v1=invalid_signature_value");

        self::assertFalse($result['verified']);
    }

    private function computeSignature(string $payload, string $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$payload}", self::WEBHOOK_SECRET);
    }
}
