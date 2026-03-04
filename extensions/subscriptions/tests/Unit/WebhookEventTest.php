<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\WebhookEvent;

use function strlen;

#[CoversClass(WebhookEvent::class)]
final class WebhookEventTest extends TestCase
{
    #[Test]
    public function createReturnsUnprocessedEvent(): void
    {
        $before = new DateTimeImmutable();

        $event = WebhookEvent::create(
            store: Store::Google,
            eventType: 'SUBSCRIPTION_RENEWED',
            payloadEncrypted: 'encrypted-payload-data',
            signatureVerified: true,
        );

        self::assertSame(32, strlen($event->id));
        self::assertSame(Store::Google, $event->store);
        self::assertSame('SUBSCRIPTION_RENEWED', $event->eventType);
        self::assertSame('encrypted-payload-data', $event->payloadEncrypted);
        self::assertTrue($event->signatureVerified);
        self::assertNull($event->processedAt);
        self::assertGreaterThanOrEqual($before, $event->createdAt);
    }

    #[Test]
    public function createGeneratesUniqueIds(): void
    {
        $event1 = WebhookEvent::create(
            store: Store::Apple,
            eventType: 'DID_RENEW',
            payloadEncrypted: 'data-1',
            signatureVerified: true,
        );

        $event2 = WebhookEvent::create(
            store: Store::Apple,
            eventType: 'DID_RENEW',
            payloadEncrypted: 'data-2',
            signatureVerified: true,
        );

        self::assertNotSame($event1->id, $event2->id);
    }

    #[Test]
    public function createWithUnverifiedSignature(): void
    {
        $event = WebhookEvent::create(
            store: Store::Google,
            eventType: 'SUBSCRIPTION_PURCHASED',
            payloadEncrypted: 'data',
            signatureVerified: false,
        );

        self::assertFalse($event->signatureVerified);
    }

    #[Test]
    public function markProcessedSetsProcessedAtTimestamp(): void
    {
        $event = WebhookEvent::create(
            store: Store::Apple,
            eventType: 'DID_RENEW',
            payloadEncrypted: 'payload',
            signatureVerified: true,
        );

        self::assertFalse($event->isProcessed());

        $processed = $event->markProcessed();

        self::assertTrue($processed->isProcessed());
        self::assertNotNull($processed->processedAt);
        self::assertGreaterThanOrEqual($event->createdAt, $processed->processedAt);
    }

    #[Test]
    public function markProcessedPreservesOriginalFields(): void
    {
        $event = WebhookEvent::create(
            store: Store::Google,
            eventType: 'SUBSCRIPTION_CANCELED',
            payloadEncrypted: 'encrypted',
            signatureVerified: true,
        );

        $processed = $event->markProcessed();

        self::assertSame($event->id, $processed->id);
        self::assertSame($event->store, $processed->store);
        self::assertSame($event->eventType, $processed->eventType);
        self::assertSame($event->payloadEncrypted, $processed->payloadEncrypted);
        self::assertSame($event->signatureVerified, $processed->signatureVerified);
        self::assertSame($event->createdAt, $processed->createdAt);
    }

    #[Test]
    public function isProcessedReturnsFalseWhenProcessedAtIsNull(): void
    {
        $event = new WebhookEvent(
            id: 'evt-id-00000000000000ab',
            store: Store::Apple,
            eventType: 'REVOKE',
            payloadEncrypted: 'data',
            signatureVerified: true,
            processedAt: null,
            createdAt: new DateTimeImmutable(),
        );

        self::assertFalse($event->isProcessed());
    }

    #[Test]
    public function isProcessedReturnsTrueWhenProcessedAtIsSet(): void
    {
        $event = new WebhookEvent(
            id: 'evt-id-00000000000000ab',
            store: Store::Apple,
            eventType: 'REVOKE',
            payloadEncrypted: 'data',
            signatureVerified: true,
            processedAt: new DateTimeImmutable(),
            createdAt: new DateTimeImmutable('-1 hour'),
        );

        self::assertTrue($event->isProcessed());
    }

    #[Test]
    public function constructorWithAppleStore(): void
    {
        $event = WebhookEvent::create(
            store: Store::Apple,
            eventType: 'EXPIRED',
            payloadEncrypted: 'apple-payload',
            signatureVerified: false,
        );

        self::assertSame(Store::Apple, $event->store);
        self::assertSame('EXPIRED', $event->eventType);
    }
}
