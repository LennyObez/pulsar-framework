<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\WritableStreamInterface;
use ReflectionClass;

#[CoversClass(WritableStreamInterface::class)]
final class WritableStreamInterfaceTest extends TestCase
{
    #[Test]
    public function interfaceDefinesAllMethods(): void
    {
        $reflection = new ReflectionClass(WritableStreamInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('write'));
        self::assertTrue($reflection->hasMethod('contents'));
        self::assertTrue($reflection->hasMethod('close'));
    }

    #[Test]
    public function stubReturnsConfiguredContent(): void
    {
        $stub = $this->createStub(WritableStreamInterface::class);
        $stub->method('contents')->willReturn('Hello World');

        self::assertSame('Hello World', $stub->contents());
    }
}
