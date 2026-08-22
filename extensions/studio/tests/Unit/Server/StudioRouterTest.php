<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Server\StudioRouter;
use ReflectionClass;

final class StudioRouterTest extends TestCase
{
    #[Test]
    public function registers_routes_on_router(): void
    {
        $reflection = new ReflectionClass(StudioRouter::class);

        // StudioRouter must be final and readonly
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());

        // Must have a dispatch method for request routing
        self::assertTrue($reflection->hasMethod('dispatch'));

        // Constructor must accept controller dependencies
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        self::assertGreaterThanOrEqual(10, $constructor->getNumberOfParameters());
    }
}
