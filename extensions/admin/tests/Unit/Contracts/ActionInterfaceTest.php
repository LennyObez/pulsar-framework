<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Admin\Contracts\ActionInterface;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\ActionResult;
use ReflectionClass;

#[CoversClass(ActionInterface::class)]
final class ActionInterfaceTest extends TestCase
{
    #[Test]
    public function interfaceDefinesExpectedMethods(): void
    {
        $reflection = new ReflectionClass(ActionInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('name'));
        self::assertTrue($reflection->hasMethod('label'));
        self::assertTrue($reflection->hasMethod('execute'));
    }

    #[Test]
    public function stubReturnsConfiguredValues(): void
    {
        $stub = $this->createStub(ActionInterface::class);
        $stub->method('name')->willReturn('archive');
        $stub->method('label')->willReturn('Archive records');
        $stub->method('execute')->willReturn(ActionResult::success('Archived'));

        self::assertSame('archive', $stub->name());
        self::assertSame('Archive records', $stub->label());

        $resource = $this->createStub(DataResourceInterface::class);
        $context = new MutationContext(actor: 'admin', reason: 'test');
        $result = $stub->execute($resource, ['1', '2'], [], $context);

        self::assertTrue($result->success);
    }
}
