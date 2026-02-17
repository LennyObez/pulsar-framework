<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Widget;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryEntry;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;
use Pulsar\Extension\Admin\Internal\Widget\RecentActivityWidget;

final class RecentActivityWidgetTest extends TestCase
{
    #[Test]
    public function id_returns_recent_activity(): void
    {
        $widget = new RecentActivityWidget($this->createStoreStub([]));

        self::assertSame('recent_activity', $widget->id());
    }

    #[Test]
    public function label_returns_human_readable(): void
    {
        $widget = new RecentActivityWidget($this->createStoreStub([]));

        self::assertSame('Recent Activity', $widget->label());
    }

    #[Test]
    public function size_returns_large(): void
    {
        $widget = new RecentActivityWidget($this->createStoreStub([]));

        self::assertSame('large', $widget->size());
    }

    #[Test]
    public function render_returns_entries(): void
    {
        $entries = [
            new ActionHistoryEntry(
                id: 'ah_001',
                action: 'create',
                resourceName: 'users',
                recordId: '42',
                actor: 'admin',
                timestamp: 1700000000,
                success: true,
            ),
            new ActionHistoryEntry(
                id: 'ah_002',
                action: 'delete',
                resourceName: 'orders',
                recordId: '99',
                actor: 'manager',
                timestamp: 1700000100,
                success: false,
                detail: 'Constraint violation',
            ),
        ];

        $widget = new RecentActivityWidget($this->createStoreStub($entries));
        $data = $widget->render();

        self::assertArrayHasKey('entries', $data);
        self::assertCount(2, $data['entries']);

        self::assertSame('ah_001', $data['entries'][0]['id']);
        self::assertSame('create', $data['entries'][0]['action']);
        self::assertSame('users', $data['entries'][0]['resource']);
        self::assertSame('42', $data['entries'][0]['record_id']);
        self::assertSame('admin', $data['entries'][0]['actor']);
        self::assertTrue($data['entries'][0]['success']);

        self::assertSame('ah_002', $data['entries'][1]['id']);
        self::assertFalse($data['entries'][1]['success']);
    }

    #[Test]
    public function render_with_no_entries(): void
    {
        $widget = new RecentActivityWidget($this->createStoreStub([]));
        $data = $widget->render();

        self::assertSame(['entries' => []], $data);
    }

    /**
     * @param list<ActionHistoryEntry> $entries
     */
    private function createStoreStub(array $entries): ActionHistoryStoreInterface&Stub
    {
        $store = $this->createStub(ActionHistoryStoreInterface::class);
        $store->method('recent')->willReturn($entries);

        return $store;
    }
}
