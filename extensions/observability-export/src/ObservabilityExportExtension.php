<?php

declare(strict_types=1);

namespace Pulsar\Extension\ObservabilityExport;

use Override;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\OpenTelemetry\Export\JsonLines\Error\ErrorExporterInterface;
use Pulsar\Extension\OpenTelemetry\Export\JsonLines\Error\JsonLinesErrorExporter;
use Pulsar\Extension\OpenTelemetry\Export\JsonLines\Metrics\JsonLinesMetricsExporter;
use Pulsar\Extension\OpenTelemetry\Export\JsonLines\Metrics\MetricsExporterInterface;
use Pulsar\Extension\OpenTelemetry\Export\JsonLines\Span\JsonLinesSpanExporter;
use Pulsar\Extension\OpenTelemetry\Export\JsonLines\Span\SpanExporterInterface;
use Pulsar\Routing\RouterInterface;

/**
 * File-based observability data export extension.
 *
 * Registers JSON Lines exporters for spans, metrics, and errors.
 * All exporters write to configurable file paths with buffered,
 * multi-process-safe I/O.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 */
#[Api(since: '1.0.0')]
final class ObservabilityExportExtension implements ExtensionInterface
{
    private const string DEFAULT_SPANS_PATH = 'var/observability/spans.jsonl';
    private const string DEFAULT_METRICS_PATH = 'var/observability/metrics.jsonl';
    private const string DEFAULT_ERRORS_PATH = 'var/observability/errors.jsonl';

    #[Override]
    public function name(): string
    {
        return 'pulsar/observability-export';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        $container->bind(
            SpanExporterInterface::class,
            static fn(): JsonLinesSpanExporter => new JsonLinesSpanExporter(
                self::DEFAULT_SPANS_PATH,
            ),
        );

        $container->bind(
            MetricsExporterInterface::class,
            static fn(): JsonLinesMetricsExporter => new JsonLinesMetricsExporter(
                self::DEFAULT_METRICS_PATH,
            ),
        );

        $container->bind(
            ErrorExporterInterface::class,
            static fn(): JsonLinesErrorExporter => new JsonLinesErrorExporter(
                self::DEFAULT_ERRORS_PATH,
            ),
        );
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // No routes or boot-time initialization required
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [];
    }
}
