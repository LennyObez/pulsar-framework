<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit\Internal\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Subscriptions\Internal\Persistence\DbWebhookEventRepository;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\WebhookEvent;

#[CoversClass(DbWebhookEventRepository::class)]
final class DbWebhookEventRepositoryTest extends TestCase
{
    #[Test]
    public function saveExecutesUpsertWithEventData(): void
    {
        $event = new WebhookEvent(
            id: 'evt-001',
            store: Store::Google,
            eventType: 'SUBSCRIPTION_RENEWED',
            payloadEncrypted: 'enc-payload',
            signatureVerified: true,
            processedAt: new DateTimeImmutable('2026-03-15T12:00:00+00:00'),
            createdAt: new DateTimeImmutable('2026-03-15T10:00:00+00:00'),
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->method('execute')->willReturn(1);

        $repo = new DbWebhookEventRepository($connection);
        $repo->save($event);

        self::assertSame('evt-001', $event->id);
    }

    #[Test]
    public function saveHandlesUnprocessedEventWithNullProcessedAt(): void
    {
        $event = new WebhookEvent(
            id: 'evt-002',
            store: Store::Apple,
            eventType: 'INITIAL_BUY',
            payloadEncrypted: 'enc-apple-payload',
            signatureVerified: false,
            processedAt: null,
            createdAt: new DateTimeImmutable('2026-03-15T10:00:00+00:00'),
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);
        $connection->method('execute')->willReturn(1);

        $repo = new DbWebhookEventRepository($connection);
        $repo->save($event);

        self::assertNull($event->processedAt);
    }

    #[Test]
    public function findByEventTypeReturnsPaginatedResults(): void
    {
        $countRow = new Row(['total' => 2]);
        $dataRow1 = new Row([
            'id' => 'evt-010',
            'store' => 'google',
            'event_type' => 'SUBSCRIPTION_RENEWED',
            'payload_encrypted' => 'enc-1',
            'signature_verified' => 1,
            'processed_at' => '2026-03-15T12:00:00+00:00',
            'created_at' => '2026-03-15T10:00:00+00:00',
        ]);
        $dataRow2 = new Row([
            'id' => 'evt-011',
            'store' => 'google',
            'event_type' => 'SUBSCRIPTION_RENEWED',
            'payload_encrypted' => 'enc-2',
            'signature_verified' => 0,
            'processed_at' => null,
            'created_at' => '2026-03-14T08:00:00+00:00',
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturnOnConsecutiveCalls(
            new Result([$countRow]),
            new Result([$dataRow1, $dataRow2]),
        );

        $repo = new DbWebhookEventRepository($connection);
        $result = $repo->findByEventType('SUBSCRIPTION_RENEWED', page: 1, perPage: 10);

        self::assertSame(2, $result->total);
        self::assertSame(1, $result->currentPage);
        self::assertSame(10, $result->perPage);
        self::assertFalse($result->hasMore);
        self::assertCount(2, $result->items);

        self::assertSame('evt-010', $result->items[0]->id);
        self::assertSame(Store::Google, $result->items[0]->store);
        self::assertTrue($result->items[0]->signatureVerified);
        self::assertNotNull($result->items[0]->processedAt);

        self::assertSame('evt-011', $result->items[1]->id);
        self::assertFalse($result->items[1]->signatureVerified);
        self::assertNull($result->items[1]->processedAt);
    }

    #[Test]
    public function findByEventTypeReturnsEmptyResultWhenNoMatches(): void
    {
        $countRow = new Row(['total' => 0]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturnOnConsecutiveCalls(
            new Result([$countRow]),
            new Result([]),
        );

        $repo = new DbWebhookEventRepository($connection);
        $result = $repo->findByEventType('NONEXISTENT_EVENT');

        self::assertSame(0, $result->total);
        self::assertCount(0, $result->items);
        self::assertSame(1, $result->lastPage);
        self::assertFalse($result->hasMore);
    }

    #[Test]
    public function findByEventTypeClampsPageToMinimumOfOne(): void
    {
        $countRow = new Row(['total' => 5]);
        $dataRow = new Row([
            'id' => 'evt-020',
            'store' => 'apple',
            'event_type' => 'CANCEL',
            'payload_encrypted' => 'enc-cancel',
            'signature_verified' => 1,
            'processed_at' => null,
            'created_at' => '2026-03-10T00:00:00+00:00',
        ]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturnOnConsecutiveCalls(
            new Result([$countRow]),
            new Result([$dataRow]),
        );

        $repo = new DbWebhookEventRepository($connection);
        // Passing page 0 should be clamped to page 1
        $result = $repo->findByEventType('CANCEL', page: 0, perPage: 5);

        self::assertSame(1, $result->currentPage);
        self::assertSame(5, $result->total);
    }

    #[Test]
    public function findByEventTypeCalculatesHasMoreCorrectly(): void
    {
        $countRow = new Row(['total' => 25]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('query')->willReturnOnConsecutiveCalls(
            new Result([$countRow]),
            new Result([]),
        );

        $repo = new DbWebhookEventRepository($connection);
        $result = $repo->findByEventType('RENEWAL', page: 1, perPage: 10);

        self::assertTrue($result->hasMore);
        self::assertSame(3, $result->lastPage);
    }
}
