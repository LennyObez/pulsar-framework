<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\ObservabilityExport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Extension\ObservabilityExport\Error\ErrorExporterInterface;
use Pulsar\Extension\ObservabilityExport\Error\JsonLinesErrorExporter;
use Pulsar\Extension\ObservabilityExport\Metrics\JsonLinesMetricsExporter;
use Pulsar\Extension\ObservabilityExport\Metrics\MetricsExporterInterface;
use Pulsar\Extension\ObservabilityExport\ObservabilityExportExtension;
use Pulsar\Extension\ObservabilityExport\Span\JsonLinesSpanExporter;
use Pulsar\Extension\ObservabilityExport\Span\SpanExporterInterface;
use Pulsar\Routing\Router;

#[CoversClass(ObservabilityExportExtension::class)]
final class ObservabilityExportExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarObservabilityExport(): void
    {
        $extension = new ObservabilityExportExtension();

        self::assertSame('pulsar/observability-export', $extension->name());
    }

    #[Test]
    public function registerBindsSpanExporter(): void
    {
        $extension = new ObservabilityExportExtension();
        $container = new Container();

        $extension->register($container);

        self::assertTrue($container->has(SpanExporterInterface::class));

        $exporter = $container->get(SpanExporterInterface::class);

        self::assertInstanceOf(JsonLinesSpanExporter::class, $exporter);
    }

    #[Test]
    public function registerBindsMetricsExporter(): void
    {
        $extension = new ObservabilityExportExtension();
        $container = new Container();

        $extension->register($container);

        self::assertTrue($container->has(MetricsExporterInterface::class));

        $exporter = $container->get(MetricsExporterInterface::class);

        self::assertInstanceOf(JsonLinesMetricsExporter::class, $exporter);
    }

    #[Test]
    public function registerBindsErrorExporter(): void
    {
        $extension = new ObservabilityExportExtension();
        $container = new Container();

        $extension->register($container);

        self::assertTrue($container->has(ErrorExporterInterface::class));

        $exporter = $container->get(ErrorExporterInterface::class);

        self::assertInstanceOf(JsonLinesErrorExporter::class, $exporter);
    }

    #[Test]
    public function bootIsNoOp(): void
    {
        $extension = new ObservabilityExportExtension();
        $container = new Container();
        $router = new Router();

        $extension->register($container);
        $extension->boot($container, $router);

        // boot() does not register any routes
        self::assertSame([], $router->routes);
    }

    #[Test]
    public function providersReturnsEmptyArray(): void
    {
        $extension = new ObservabilityExportExtension();

        self::assertSame([], $extension->providers());
    }
}
