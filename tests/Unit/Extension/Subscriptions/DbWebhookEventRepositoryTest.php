<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Subscriptions;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Extension\Subscriptions\Internal\Persistence\DbWebhookEventRepository;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\WebhookEvent;

#[CoversClass(DbWebhookEventRepository::class)]
final class DbWebhookEventRepositoryTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function eventRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'evt-001',
            'store' => 'google',
            'event_type' => 'SUBSCRIPTION_RENEWED',
            'payload_encrypted' => 'encrypted-data',
            'signature_verified' => 1,
            'processed_at' => null,
            'created_at' => '2026-01-15T10:00:00+00:00',
        ], $overrides);
    }

    // ── save ─────────────────────────────────────────────────────────

    #[Test]
    public function saveCallsExecuteWithUpsertQuery(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->expects($this->once())->method('execute');

        $event = new WebhookEvent(
            id: 'evt-001',
            store: Store::Google,
            eventType: 'SUBSCRIPTION_RENEWED',
            payloadEncrypted: 'encrypted-data',
            signatureVerified: true,
            processedAt: null,
            createdAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
        );

        $repo = new DbWebhookEventRepository($db);
        $repo->save($event);
    }

    // ── findByEventType ──────────────────────────────────────────────

    #[Test]
    public function findByEventTypeReturnsPaginatedResults(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 1]]),
            Result::fromArrays([$this->eventRow()]),
        );

        $repo = new DbWebhookEventRepository($db);
        $result = $repo->findByEventType('SUBSCRIPTION_RENEWED');

        self::assertSame(1, $result->total);
        self::assertCount(1, $result->items);
        self::assertSame(1, $result->currentPage);
        self::assertFalse($result->hasMore);
        self::assertSame(20, $result->perPage);

        $event = $result->items[0];
        self::assertInstanceOf(WebhookEvent::class, $event);
        self::assertSame('evt-001', $event->id);
        self::assertSame(Store::Google, $event->store);
        self::assertSame('SUBSCRIPTION_RENEWED', $event->eventType);
        self::assertSame('encrypted-data', $event->payloadEncrypted);
        self::assertTrue($event->signatureVerified);
        self::assertNull($event->processedAt);
    }

    #[Test]
    public function findByEventTypeReturnsEmptyWhenNoneExist(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 0]]),
            Result::fromArrays([]),
        );

        $repo = new DbWebhookEventRepository($db);
        $result = $repo->findByEventType('SUBSCRIPTION_CANCELLED');

        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);
        self::assertFalse($result->hasMore);
    }

    #[Test]
    public function findByEventTypeWithMultiplePagesHasMore(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 50]]),
            Result::fromArrays([$this->eventRow()]),
        );

        $repo = new DbWebhookEventRepository($db);
        $result = $repo->findByEventType('SUBSCRIPTION_RENEWED', page: 1, perPage: 10);

        self::assertSame(50, $result->total);
        self::assertTrue($result->hasMore);
        self::assertSame(5, $result->lastPage);
        self::assertSame(1, $result->currentPage);
    }

    #[Test]
    public function findByEventTypeClampsPageToMinimumOfOne(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 0]]),
            Result::fromArrays([]),
        );

        $repo = new DbWebhookEventRepository($db);
        $result = $repo->findByEventType('SUBSCRIPTION_RENEWED', page: -3);

        self::assertSame(1, $result->currentPage);
    }

    // ── hydration edge cases ─────────────────────────────────────────

    #[Test]
    public function hydratesEventWithProcessedAt(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 1]]),
            Result::fromArrays([$this->eventRow([
                'processed_at' => '2026-01-16T10:00:00+00:00',
                'signature_verified' => 0,
            ])]),
        );

        $repo = new DbWebhookEventRepository($db);
        $result = $repo->findByEventType('SUBSCRIPTION_RENEWED');

        $event = $result->items[0];
        self::assertInstanceOf(WebhookEvent::class, $event);
        self::assertNotNull($event->processedAt);
        self::assertFalse($event->signatureVerified);
    }
}
