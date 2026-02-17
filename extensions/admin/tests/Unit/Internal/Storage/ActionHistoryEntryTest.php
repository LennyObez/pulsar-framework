<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Storage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryEntry;

final class ActionHistoryEntryTest extends TestCase
{
    #[Test]
    public function construction_with_all_fields(): void
    {
        $entry = new ActionHistoryEntry(
            id: 'ah_001',
            action: 'create',
            resourceName: 'users',
            recordId: '42',
            actor: 'admin@test.com',
            timestamp: 1700000000,
            success: true,
            detail: 'Created user Alice',
        );

        self::assertSame('ah_001', $entry->id);
        self::assertSame('create', $entry->action);
        self::assertSame('users', $entry->resourceName);
        self::assertSame('42', $entry->recordId);
        self::assertSame('admin@test.com', $entry->actor);
        self::assertSame(1700000000, $entry->timestamp);
        self::assertTrue($entry->success);
        self::assertSame('Created user Alice', $entry->detail);
    }

    #[Test]
    public function construction_with_defaults(): void
    {
        $entry = new ActionHistoryEntry(
            id: 'ah_002',
            action: 'delete',
            resourceName: 'orders',
            recordId: null,
            actor: 'system',
            timestamp: 1700000100,
            success: false,
        );

        self::assertNull($entry->recordId);
        self::assertSame('', $entry->detail);
        self::assertFalse($entry->success);
    }
}
