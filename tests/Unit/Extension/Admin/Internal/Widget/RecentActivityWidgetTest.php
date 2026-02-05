<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Internal\Widget;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryEntry;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;
use Pulsar\Extension\Admin\Internal\Widget\RecentActivityWidget;

#[CoversClass(RecentActivityWidget::class)]
final class RecentActivityWidgetTest extends TestCase
{
    #[Test]
    public function idReturnsRecentActivity(): void
    {
        $store = $this->createStub(ActionHistoryStoreInterface::class);
        $store->method('recent')->willReturn([]);

        $widget = new RecentActivityWidget($store);

        self::assertSame('recent_activity', $widget->id());
    }

    #[Test]
    public function labelReturnsRecentActivity(): void
    {
        $store = $this->createStub(ActionHistoryStoreInterface::class);
        $store->method('recent')->willReturn([]);

        $widget = new RecentActivityWidget($store);

        self::assertSame('Recent Activity', $widget->label());
    }

    #[Test]
    public function sizeReturnsLarge(): void
    {
        $store = $this->createStub(ActionHistoryStoreInterface::class);
        $store->method('recent')->willReturn([]);

        $widget = new RecentActivityWidget($store);

        self::assertSame('large', $widget->size());
    }

    #[Test]
    public function renderReturnsEmptyEntriesWhenNoHistory(): void
    {
        $store = $this->createStub(ActionHistoryStoreInterface::class);
        $store->method('recent')->willReturn([]);

        $widget = new RecentActivityWidget($store);
        $data = $widget->render();

        self::assertArrayHasKey('entries', $data);
        self::assertSame([], $data['entries']);
    }

    #[Test]
    public function renderMapsHistoryEntriesToArrays(): void
    {
        $entry1 = new ActionHistoryEntry(
            id: 'entry-1',
            action: 'create',
            resourceName: 'users',
            recordId: '42',
            actor: 'admin',
            timestamp: 1700000000,
            success: true,
        );

        $entry2 = new ActionHistoryEntry(
            id: 'entry-2',
            action: 'delete',
            resourceName: 'orders',
            recordId: '99',
            actor: 'moderator',
            timestamp: 1700000100,
            success: false,
        );

        $store = $this->createStub(ActionHistoryStoreInterface::class);
        $store->method('recent')->willReturn([$entry1, $entry2]);

        $widget = new RecentActivityWidget($store);
        $data = $widget->render();

        /** @var list<array<string, mixed>> $entries */
        $entries = $data['entries'];
        self::assertCount(2, $entries);

        self::assertSame('entry-1', $entries[0]['id']);
        self::assertSame('create', $entries[0]['action']);
        self::assertSame('users', $entries[0]['resource']);
        self::assertSame('42', $entries[0]['record_id']);
        self::assertSame('admin', $entries[0]['actor']);
        self::assertSame(1700000000, $entries[0]['timestamp']);
        self::assertTrue($entries[0]['success']);

        self::assertSame('entry-2', $entries[1]['id']);
        self::assertSame('delete', $entries[1]['action']);
        self::assertFalse($entries[1]['success']);
    }

    #[Test]
    public function renderRequestsTwentyEntries(): void
    {
        $store = $this->createMock(ActionHistoryStoreInterface::class);
        $store->expects(self::once())
            ->method('recent')
            ->with(20)
            ->willReturn([]);

        $widget = new RecentActivityWidget($store);
        $widget->render();
    }
}
