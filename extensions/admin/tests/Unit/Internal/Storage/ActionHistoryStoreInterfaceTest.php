<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Storage;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryEntry;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;
use ReflectionClass;

#[CoversNothing]
final class ActionHistoryStoreInterfaceTest extends TestCase
{
    #[Test]
    public function interfaceDefinesExpectedMethods(): void
    {
        $reflection = new ReflectionClass(ActionHistoryStoreInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('record'));
        self::assertTrue($reflection->hasMethod('recent'));
        self::assertTrue($reflection->hasMethod('forResource'));
    }

    #[Test]
    public function stubReturnsConfiguredEntries(): void
    {
        $entry = new ActionHistoryEntry(
            id: 'e1',
            action: 'create',
            resourceName: 'users',
            recordId: '42',
            actor: 'admin',
            timestamp: 1711612800,
            success: true,
        );

        $store = $this->createStub(ActionHistoryStoreInterface::class);
        $store->method('recent')->willReturn([$entry]);
        $store->method('forResource')->willReturn([$entry]);

        self::assertCount(1, $store->recent());
        self::assertCount(1, $store->forResource('users'));
    }
}
