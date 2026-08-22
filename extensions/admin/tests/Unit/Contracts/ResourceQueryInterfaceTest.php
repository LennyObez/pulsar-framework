<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use ReflectionClass;

#[CoversNothing]
final class ResourceQueryInterfaceTest extends TestCase
{
    #[Test]
    public function interfaceDefinesAllMethods(): void
    {
        $reflection = new ReflectionClass(ResourceQueryInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('list'));
        self::assertTrue($reflection->hasMethod('find'));
        self::assertTrue($reflection->hasMethod('search'));
        self::assertTrue($reflection->hasMethod('count'));
    }

    #[Test]
    public function stubReturnsConfiguredValues(): void
    {
        $stub = $this->createStub(ResourceQueryInterface::class);
        $stub->method('list')->willReturn([
            'data' => [['id' => '1']],
            'total' => 1,
            'page' => 1,
            'per_page' => 25,
        ]);
        $stub->method('find')->willReturn(['id' => '1', 'name' => 'Test']);
        $stub->method('search')->willReturn([['id' => '1']]);
        $stub->method('count')->willReturn(42);

        $resource = $this->createStub(DataResourceInterface::class);

        self::assertSame(1, $stub->list($resource)['total']);
        self::assertSame('1', $stub->find($resource, '1')['id']);
        self::assertCount(1, $stub->search($resource, 'test'));
        self::assertSame(42, $stub->count($resource));
    }

    #[Test]
    public function stubFindReturnsNull(): void
    {
        $stub = $this->createStub(ResourceQueryInterface::class);
        $stub->method('find')->willReturn(null);

        $resource = $this->createStub(DataResourceInterface::class);

        self::assertNull($stub->find($resource, 'nonexistent'));
    }
}
