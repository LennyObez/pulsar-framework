<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceMutatorInterface;
use Pulsar\Extension\Admin\Domain\ActionResult;
use ReflectionClass;

#[CoversNothing]
final class ResourceMutatorInterfaceTest extends TestCase
{
    #[Test]
    public function interfaceDefinesAllMethods(): void
    {
        $reflection = new ReflectionClass(ResourceMutatorInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('create'));
        self::assertTrue($reflection->hasMethod('update'));
        self::assertTrue($reflection->hasMethod('delete'));
        self::assertTrue($reflection->hasMethod('bulkAction'));
    }

    #[Test]
    public function stubReturnsActionResults(): void
    {
        $stub = $this->createStub(ResourceMutatorInterface::class);
        $stub->method('create')->willReturn(ActionResult::success('Created'));
        $stub->method('update')->willReturn(ActionResult::success('Updated'));
        $stub->method('delete')->willReturn(ActionResult::success('Deleted'));
        $stub->method('bulkAction')->willReturn(ActionResult::success('Bulk done'));

        $resource = $this->createStub(DataResourceInterface::class);
        $context = new MutationContext(actor: 'admin', reason: 'test');

        self::assertTrue($stub->create($resource, ['name' => 'Test'], $context)->success);
        self::assertTrue($stub->update($resource, '1', ['name' => 'Updated'], $context)->success);
        self::assertTrue($stub->delete($resource, '1', $context)->success);
        self::assertTrue($stub->bulkAction($resource, 'archive', ['1'], [], $context)->success);
    }
}
