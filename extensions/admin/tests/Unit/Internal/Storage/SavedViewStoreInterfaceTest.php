<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\SavedView;
use Pulsar\Extension\Admin\Internal\Storage\SavedViewStoreInterface;
use ReflectionClass;

#[CoversClass(SavedViewStoreInterface::class)]
final class SavedViewStoreInterfaceTest extends TestCase
{
    #[Test]
    public function interfaceDefinesExpectedMethods(): void
    {
        $reflection = new ReflectionClass(SavedViewStoreInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('listForResource'));
        self::assertTrue($reflection->hasMethod('find'));
        self::assertTrue($reflection->hasMethod('save'));
        self::assertTrue($reflection->hasMethod('delete'));
    }

    #[Test]
    public function stubReturnsConfiguredValues(): void
    {
        $view = new SavedView(
            id: 'v1',
            resourceName: 'users',
            label: 'All Users',
            filters: [],
            sort: [],
            perPage: 25,
            createdBy: 'admin',
        );

        $store = $this->createStub(SavedViewStoreInterface::class);
        $store->method('listForResource')->willReturn([$view]);
        $store->method('find')->willReturn($view);

        self::assertCount(1, $store->listForResource('users'));
        self::assertSame('v1', $store->find('v1')?->id);
    }

    #[Test]
    public function stubFindReturnsNullForMissing(): void
    {
        $store = $this->createStub(SavedViewStoreInterface::class);
        $store->method('find')->willReturn(null);

        self::assertNull($store->find('nonexistent'));
    }
}
