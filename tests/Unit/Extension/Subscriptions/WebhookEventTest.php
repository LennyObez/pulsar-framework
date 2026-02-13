<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Subscriptions;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\WebhookEvent;

use function strlen;

final class WebhookEventTest extends TestCase
{
    #[Test]
    public function createGeneratesIdAndSetsTimestamp(): void
    {
        $event = WebhookEvent::create(
            store: Store::Google,
            eventType: 'SUBSCRIPTION_RENEWED',
            payloadEncrypted: 'encrypted-payload',
            signatureVerified: true,
        );

        self::assertSame(32, strlen($event->id));
        self::assertSame(Store::Google, $event->store);
        self::assertSame('SUBSCRIPTION_RENEWED', $event->eventType);
        self::assertSame('encrypted-payload', $event->payloadEncrypted);
        self::assertTrue($event->signatureVerified);
        self::assertNull($event->processedAt);
        self::assertInstanceOf(DateTimeImmutable::class, $event->createdAt);
    }

    #[Test]
    public function createWithUnverifiedSignature(): void
    {
        $event = WebhookEvent::create(
            store: Store::Apple,
            eventType: 'DID_RENEW',
            payloadEncrypted: 'data',
            signatureVerified: false,
        );

        self::assertFalse($event->signatureVerified);
        self::assertSame(Store::Apple, $event->store);
    }

    #[Test]
    public function markProcessedSetsTimestamp(): void
    {
        $event = WebhookEvent::create(
            store: Store::Google,
            eventType: 'SUBSCRIPTION_CANCELED',
            payloadEncrypted: 'data',
            signatureVerified: true,
        );

        self::assertNull($event->processedAt);

        $processed = $event->markProcessed();

        self::assertNotNull($processed->processedAt);
        self::assertInstanceOf(DateTimeImmutable::class, $processed->processedAt);
        self::assertSame($event->id, $processed->id);
        self::assertSame($event->store, $processed->store);
        self::assertSame($event->eventType, $processed->eventType);
    }

    #[Test]
    public function isProcessedReturnsFalseBeforeMarking(): void
    {
        $event = WebhookEvent::create(
            store: Store::Apple,
            eventType: 'EXPIRED',
            payloadEncrypted: 'data',
            signatureVerified: true,
        );

        self::assertFalse($event->isProcessed());
    }

    #[Test]
    public function isProcessedReturnsTrueAfterMarking(): void
    {
        $event = WebhookEvent::create(
            store: Store::Apple,
            eventType: 'DID_RENEW',
            payloadEncrypted: 'data',
            signatureVerified: true,
        );

        $processed = $event->markProcessed();

        self::assertTrue($processed->isProcessed());
    }

    #[Test]
    public function constructorAcceptsAllParameters(): void
    {
        $now = new DateTimeImmutable();
        $processedAt = new DateTimeImmutable('+1 minute');

        $event = new WebhookEvent(
            id: 'evt-001',
            store: Store::Google,
            eventType: 'SUBSCRIPTION_PURCHASED',
            payloadEncrypted: 'encrypted-data',
            signatureVerified: true,
            processedAt: $processedAt,
            createdAt: $now,
        );

        self::assertSame('evt-001', $event->id);
        self::assertSame(Store::Google, $event->store);
        self::assertSame('SUBSCRIPTION_PURCHASED', $event->eventType);
        self::assertSame('encrypted-data', $event->payloadEncrypted);
        self::assertTrue($event->signatureVerified);
        self::assertSame($processedAt, $event->processedAt);
        self::assertSame($now, $event->createdAt);
    }
}
