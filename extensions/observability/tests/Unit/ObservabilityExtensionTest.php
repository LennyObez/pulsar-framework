<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\ObservabilityExtension;

#[CoversClass(ObservabilityExtension::class)]
final class ObservabilityExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarObservability(): void
    {
        $extension = new ObservabilityExtension();

        self::assertSame('pulsar/observability', $extension->name());
    }

    #[Test]
    public function providersReturnsEmptyArray(): void
    {
        $extension = new ObservabilityExtension();

        self::assertSame([], $extension->providers());
    }
}
