<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\WidgetInterface;
use ReflectionClass;

#[CoversNothing]
final class WidgetInterfaceTest extends TestCase
{
    #[Test]
    public function interfaceDefinesAllMethods(): void
    {
        $reflection = new ReflectionClass(WidgetInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('id'));
        self::assertTrue($reflection->hasMethod('label'));
        self::assertTrue($reflection->hasMethod('size'));
        self::assertTrue($reflection->hasMethod('render'));
    }

    #[Test]
    public function stubReturnsConfiguredValues(): void
    {
        $stub = $this->createStub(WidgetInterface::class);
        $stub->method('id')->willReturn('resource_count');
        $stub->method('label')->willReturn('Total Resources');
        $stub->method('size')->willReturn('small');
        $stub->method('render')->willReturn(['count' => 42]);

        self::assertSame('resource_count', $stub->id());
        self::assertSame('Total Resources', $stub->label());
        self::assertSame('small', $stub->size());
        self::assertSame(42, $stub->render()['count']);
    }
}
