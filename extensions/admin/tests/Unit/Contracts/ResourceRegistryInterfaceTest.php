<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use ReflectionClass;

#[CoversNothing]
final class ResourceRegistryInterfaceTest extends TestCase
{
    #[Test]
    public function interfaceDefinesAllMethods(): void
    {
        $reflection = new ReflectionClass(ResourceRegistryInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('register'));
        self::assertTrue($reflection->hasMethod('get'));
        self::assertTrue($reflection->hasMethod('has'));
        self::assertTrue($reflection->hasMethod('all'));
    }
}
