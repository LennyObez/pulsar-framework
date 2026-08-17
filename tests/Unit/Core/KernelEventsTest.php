<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Core\KernelEvents;

#[CoversNothing]
final class KernelEventsTest extends TestCase
{
    #[Test]
    public function terminateEventHasCorrectValue(): void
    {
        self::assertSame('kernel.terminate', KernelEvents::TERMINATE->value);
    }

    #[Test]
    public function bootEventHasCorrectValue(): void
    {
        self::assertSame('kernel.boot', KernelEvents::BOOT->value);
    }

    #[Test]
    public function shutdownEventHasCorrectValue(): void
    {
        self::assertSame('kernel.shutdown', KernelEvents::SHUTDOWN->value);
    }

    #[Test]
    public function allCasesAreUnique(): void
    {
        $values = array_map(
            static fn(KernelEvents $e): string => $e->value,
            KernelEvents::cases(),
        );

        self::assertSame($values, array_unique($values));
    }
}
