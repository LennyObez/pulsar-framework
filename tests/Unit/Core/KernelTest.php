<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Core\Kernel;

#[CoversClass(Kernel::class)]
final class KernelTest extends TestCase
{
    #[Test]
    public function kernelIsNotBootedByDefault(): void
    {
        $kernel = new Kernel();

        self::assertFalse($kernel->isBooted());
    }

    #[Test]
    public function kernelCanBoot(): void
    {
        $kernel = new Kernel();
        $kernel->boot();

        self::assertTrue($kernel->isBooted());
    }

    #[Test]
    public function kernelBootIsIdempotent(): void
    {
        $kernel = new Kernel();
        $kernel->boot();
        $kernel->boot();

        self::assertTrue($kernel->isBooted());
    }

    #[Test]
    public function kernelCanShutdown(): void
    {
        $kernel = new Kernel();
        $kernel->boot();
        $kernel->shutdown();

        self::assertFalse($kernel->isBooted());
    }
}
