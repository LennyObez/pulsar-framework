<?php

declare(strict_types=1);

namespace Pulsar\Extension\ObservabilityExportTests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\ObservabilityExport\Error\ErrorExporterInterface;
use Pulsar\Extension\ObservabilityExport\Metrics\MetricsExporterInterface;
use Pulsar\Extension\ObservabilityExport\ObservabilityExportExtension;
use Pulsar\Extension\ObservabilityExport\Span\SpanExporterInterface;
use Pulsar\Routing\RouterInterface;

final class ObservabilityExportExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarObservabilityExport(): void
    {
        $ext = new ObservabilityExportExtension();

        self::assertSame('pulsar/observability-export', $ext->name());
    }

    #[Test]
    public function registerBindsThreeExporterInterfaces(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $boundClasses = [];
        $container->method('bind')->willReturnCallback(
            function (string $abstract) use (&$boundClasses): void {
                $boundClasses[] = $abstract;
            },
        );

        $ext = new ObservabilityExportExtension();
        $ext->register($container);

        self::assertContains(SpanExporterInterface::class, $boundClasses);
        self::assertContains(MetricsExporterInterface::class, $boundClasses);
        self::assertContains(ErrorExporterInterface::class, $boundClasses);
    }

    #[Test]
    public function providersReturnsEmptyArray(): void
    {
        $ext = new ObservabilityExportExtension();

        self::assertSame([], $ext->providers());
    }

    #[Test]
    public function bootDoesNotThrow(): void
    {
        $ext = new ObservabilityExportExtension();
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createStub(RouterInterface::class);

        $ext->boot($container, $router);

        $this->addToAssertionCount(1);
    }
}
