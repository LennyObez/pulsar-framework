<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Subscriptions\Store;
use Pulsar\Extension\Subscriptions\WebhookEvent;
use Pulsar\Extension\Subscriptions\WebhookEventRepositoryInterface;

#[CoversClass(WebhookEventRepositoryInterface::class)]
final class WebhookEventRepositoryInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanAcceptSaveCall(): void
    {
        $event = WebhookEvent::create(
            store: Store::Google,
            eventType: 'SUBSCRIPTION_PURCHASED',
            payloadEncrypted: 'encrypted-data',
            signatureVerified: true,
        );

        $stub = $this->createStub(WebhookEventRepositoryInterface::class);

        // save() returns void -- just verify no exception
        $stub->save($event);
        self::assertSame('SUBSCRIPTION_PURCHASED', $event->eventType);
    }

    #[Test]
    public function stubCanReturnPaginatedResults(): void
    {
        $event = WebhookEvent::create(
            store: Store::Apple,
            eventType: 'CANCEL',
            payloadEncrypted: 'enc',
            signatureVerified: false,
        );

        $paginatedResult = new PaginationResult(
            items: [$event],
            total: 1,
            hasMore: false,
            perPage: 20,
            currentPage: 1,
            lastPage: 1,
        );

        $stub = $this->createStub(WebhookEventRepositoryInterface::class);
        $stub->method('findByEventType')->willReturn($paginatedResult);

        $result = $stub->findByEventType('CANCEL');

        self::assertSame(1, $result->total);
        self::assertCount(1, $result->items);
        self::assertFalse($result->hasMore);
    }

    #[Test]
    public function stubCanReturnEmptyPaginatedResult(): void
    {
        $paginatedResult = new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 20,
            currentPage: 1,
            lastPage: 1,
        );

        $stub = $this->createStub(WebhookEventRepositoryInterface::class);
        $stub->method('findByEventType')->willReturn($paginatedResult);

        $result = $stub->findByEventType('NONEXISTENT', page: 1, perPage: 20);

        self::assertSame(0, $result->total);
        self::assertCount(0, $result->items);
    }
}
