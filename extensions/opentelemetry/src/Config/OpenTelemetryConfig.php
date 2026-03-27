<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Environment;

use function explode;
use function str_contains;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Root configuration DTO for the OpenTelemetry extension.
 *
 * Environment variables override file values following OTel SDK conventions:
 * - OTEL_EXPORTER_OTLP_ENDPOINT → endpoint
 * - OTEL_EXPORTER_OTLP_PROTOCOL → protocol
 * - OTEL_SERVICE_NAME → serviceName
 * - OTEL_TRACES_SAMPLER → sampler.type
 * - OTEL_TRACES_SAMPLER_ARG → sampler.probability
 * - OTEL_EXPORTER_OTLP_HEADERS → headers (comma-separated key=value)
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class OpenTelemetryConfig
{
    /**
     * @param bool                  $enabled          Master kill-switch for all OTLP export
     * @param string                $endpoint         Base OTLP endpoint URL
     * @param OtlpProtocol          $protocol         Transport protocol
     * @param int                   $timeoutMs        Export timeout in milliseconds
     * @param array<string, string> $headers          Extra HTTP headers sent with every request
     * @param string                $serviceName      service.name resource attribute
     * @param string                $serviceVersion   service.version resource attribute
     * @param string                $serviceNamespace  service.namespace resource attribute
     * @param OtlpTracesConfig      $traces           Traces signal configuration
     * @param OtlpMetricsConfig     $metrics          Metrics signal configuration
     * @param OtlpLogsConfig        $logs             Logs signal configuration
     * @param SamplerConfig         $sampler          Trace sampler configuration
     * @param list<string>          $propagators      Context propagation formats (reserved; not yet wired to middleware)
     * @param BatchConfig           $batch            Batch exporter configuration
     * @param CardinalityConfig     $cardinality      Cardinality limiting configuration
     * @param bool                  $dualExport       Keeps existing span processor (e.g., Studio) active alongside OTLP export
     */
    public function __construct(
        public bool $enabled = false,
        public string $endpoint = 'http://localhost:4318',
        public OtlpProtocol $protocol = OtlpProtocol::HttpProtobuf,
        public int $timeoutMs = 5000,
        public array $headers = [],
        public string $serviceName = '',
        public string $serviceVersion = '',
        public string $serviceNamespace = '',
        public OtlpTracesConfig $traces = new OtlpTracesConfig(),
        public OtlpMetricsConfig $metrics = new OtlpMetricsConfig(),
        public OtlpLogsConfig $logs = new OtlpLogsConfig(),
        public SamplerConfig $sampler = new SamplerConfig(),
        public array $propagators = ['tracecontext', 'baggage'],
        public BatchConfig $batch = new BatchConfig(),
        public CardinalityConfig $cardinality = new CardinalityConfig(),
        public bool $dualExport = false,
    ) {}

    /**
     * Build config from a raw array with optional environment overrides.
     *
     * @param array{
     *     enabled?: bool,
     *     endpoint?: string,
     *     protocol?: string,
     *     timeout_ms?: int,
     *     headers?: array<string, string>,
     *     service_name?: string,
     *     service_version?: string,
     *     service_namespace?: string,
     *     traces?: array<string, mixed>,
     *     metrics?: array<string, mixed>,
     *     logs?: array<string, mixed>,
     *     sampler?: array<string, mixed>,
     *     propagators?: list<string>,
     *     batch?: array<string, mixed>,
     *     cardinality?: array<string, mixed>,
     *     dual_export?: bool,
     * } $data Raw array from config/opentelemetry.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, ?Environment $environment = null): self
    {
        $traces = OtlpTracesConfig::fromArray($data['traces'] ?? []);
        $metrics = OtlpMetricsConfig::fromArray($data['metrics'] ?? []);
        $logs = OtlpLogsConfig::fromArray($data['logs'] ?? []);
        $sampler = SamplerConfig::fromArray($data['sampler'] ?? []);
        $batch = BatchConfig::fromArray($data['batch'] ?? []);
        $cardinality = CardinalityConfig::fromArray($data['cardinality'] ?? []);

        $enabled = $data['enabled'] ?? false;
        $endpoint = $data['endpoint'] ?? 'http://localhost:4318';
        $protocol = OtlpProtocol::tryFrom($data['protocol'] ?? '') ?? OtlpProtocol::HttpProtobuf;
        $timeoutMs = $data['timeout_ms'] ?? 5000;
        $headers = $data['headers'] ?? [];
        $serviceName = $data['service_name'] ?? '';
        $serviceVersion = $data['service_version'] ?? '';
        $serviceNamespace = $data['service_namespace'] ?? '';
        $propagators = $data['propagators'] ?? ['tracecontext', 'baggage'];
        $dualExport = $data['dual_export'] ?? false;

        // Apply environment variable overrides
        if ($environment !== null) {
            $envEndpoint = $environment->get('OTEL_EXPORTER_OTLP_ENDPOINT');

            if ($envEndpoint !== null) {
                $endpoint = $envEndpoint;
            }

            $envProtocol = $environment->get('OTEL_EXPORTER_OTLP_PROTOCOL');

            if ($envProtocol !== null) {
                $protocol = OtlpProtocol::tryFrom($envProtocol) ?? $protocol;
            }

            $envServiceName = $environment->get('OTEL_SERVICE_NAME');

            if ($envServiceName !== null) {
                $serviceName = $envServiceName;
            }

            $envSamplerType = $environment->get('OTEL_TRACES_SAMPLER');

            if ($envSamplerType !== null) {
                $mappedType = self::mapOtelSamplerName($envSamplerType);

                if ($mappedType !== null) {
                    $samplerType = $mappedType;
                    $samplerProbability = $sampler->probability;

                    $envSamplerArg = $environment->get('OTEL_TRACES_SAMPLER_ARG');

                    if ($envSamplerArg !== null) {
                        $parsed = (float) $envSamplerArg;
                        $samplerProbability = ($parsed >= 0.0 && $parsed <= 1.0) ? $parsed : $sampler->probability;
                    }

                    $sampler = new SamplerConfig(
                        type: $samplerType,
                        probability: $samplerProbability,
                        ratePerSecond: $sampler->ratePerSecond,
                    );
                }
            }

            $envHeaders = $environment->get('OTEL_EXPORTER_OTLP_HEADERS');

            if ($envHeaders !== null) {
                $headers = self::parseHeaderString($envHeaders);
            }
        }

        return new self(
            enabled: $enabled,
            endpoint: $endpoint,
            protocol: $protocol,
            timeoutMs: $timeoutMs,
            headers: $headers,
            serviceName: $serviceName,
            serviceVersion: $serviceVersion,
            serviceNamespace: $serviceNamespace,
            traces: $traces,
            metrics: $metrics,
            logs: $logs,
            sampler: $sampler,
            propagators: $propagators,
            batch: $batch,
            cardinality: $cardinality,
            dualExport: $dualExport,
        );
    }

    /**
     * Map OTel SDK sampler names to Pulsar SamplerType.
     */
    private static function mapOtelSamplerName(string $name): ?SamplerType
    {
        return match (strtolower($name)) {
            'always_on' => SamplerType::Always,
            'always_off', 'parentbased_always_off' => SamplerType::Never,
            'traceidratio' => SamplerType::Probability,
            'parentbased_always_on', 'parentbased_traceidratio' => SamplerType::ParentBased,
            default => SamplerType::tryFrom($name),
        };
    }

    /**
     * Parse comma-separated key=value header string.
     *
     * @return array<string, string>
     */
    private static function parseHeaderString(string $headerString): array
    {
        $headers = [];
        $pairs = explode(',', $headerString);

        foreach ($pairs as $pair) {
            $pair = trim($pair);

            $equalsPos = strpos($pair, '=');

            if ($pair === '' || $equalsPos === false) {
                continue;
            }
            $key = trim(substr($pair, 0, $equalsPos));
            $value = trim(substr($pair, $equalsPos + 1));

            // S4: Reject headers with CR/LF to prevent header injection
            if ($key !== '' && !str_contains($key, "\r") && !str_contains($key, "\n")
                && !str_contains($value, "\r") && !str_contains($value, "\n")) {
                $headers[$key] = $value;
            }
        }

        return $headers;
    }
}
