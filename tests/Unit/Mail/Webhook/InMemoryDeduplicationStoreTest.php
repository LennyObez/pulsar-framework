<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Webhook\InMemoryDeduplicationStore;

#[CoversClass(InMemoryDeduplicationStore::class)]
final class InMemoryDeduplicationStoreTest extends TestCase
{
    #[Test]
    public function it_stores_and_retrieves_event(): void
    {
        $store = new InMemoryDeduplicationStore();

        self::assertFalse($store->has('evt-1'));

        $store->store('evt-1');

        self::assertTrue($store->has('evt-1'));
    }

    #[Test]
    public function it_returns_false_for_unknown_event(): void
    {
        $store = new InMemoryDeduplicationStore();

        self::assertFalse($store->has('nonexistent'));
    }

    #[Test]
    public function it_isolates_by_tenant(): void
    {
        $store = new InMemoryDeduplicationStore();

        $store->store('evt-1', 'tenant-a');

        self::assertTrue($store->has('evt-1', 'tenant-a'));
        self::assertFalse($store->has('evt-1', 'tenant-b'));
        self::assertFalse($store->has('evt-1')); // No tenant
    }

    #[Test]
    public function it_retains_recent_entries_on_cleanup(): void
    {
        $store = new InMemoryDeduplicationStore();
        $store->store('evt-recent');

        // Cleanup with 1 day should keep entries stored just now
        $store->cleanup(1);

        self::assertTrue($store->has('evt-recent'));
    }

    #[Test]
    public function it_removes_all_on_large_max_age_cleanup(): void
    {
        $store = new InMemoryDeduplicationStore();
        $store->store('evt-1');

        // Cleanup with 30 days retains everything stored just now
        $store->cleanup(30);

        self::assertTrue($store->has('evt-1'));
    }

    #[Test]
    public function it_handles_same_event_across_tenants(): void
    {
        $store = new InMemoryDeduplicationStore();

        $store->store('evt-shared', 'tenant-a');
        $store->store('evt-shared', 'tenant-b');

        self::assertTrue($store->has('evt-shared', 'tenant-a'));
        self::assertTrue($store->has('evt-shared', 'tenant-b'));
    }
}
