<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\Storage\SchemaChangeLogStoreInterface;
use ReflectionClass;

#[CoversClass(SchemaChangeLogStoreInterface::class)]
final class SchemaChangeLogStoreInterfaceTest extends TestCase
{
    #[Test]
    public function interface_defines_expected_methods(): void
    {
        $reflection = new ReflectionClass(SchemaChangeLogStoreInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('record'));
        self::assertTrue($reflection->hasMethod('recent'));
        self::assertTrue($reflection->hasMethod('forTable'));
        self::assertTrue($reflection->hasMethod('exportSqlBundle'));
    }

    #[Test]
    public function stub_returns_configured_values(): void
    {
        $store = $this->createStub(SchemaChangeLogStoreInterface::class);
        $store->method('recent')->willReturn([]);
        $store->method('forTable')->willReturn([]);
        $store->method('exportSqlBundle')->willReturn('-- empty bundle');

        self::assertSame([], $store->recent());
        self::assertSame([], $store->forTable('users'));
        self::assertSame('-- empty bundle', $store->exportSqlBundle());
    }
}
