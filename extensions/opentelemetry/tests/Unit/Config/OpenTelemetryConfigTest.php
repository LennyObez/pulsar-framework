<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Config\OpenTelemetryConfig;
use Pulsar\Extension\OpenTelemetry\Config\OtlpProtocol;
use Pulsar\Extension\OpenTelemetry\Config\SamplerType;
use ReflectionClass;

#[CoversClass(OpenTelemetryConfig::class)]
final class OpenTelemetryConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new OpenTelemetryConfig();

        self::assertFalse($config->enabled);
        self::assertSame('http://localhost:4318', $config->endpoint);
        self::assertSame(OtlpProtocol::HttpProtobuf, $config->protocol);
        self::assertSame(5000, $config->timeoutMs);
        self::assertSame([], $config->headers);
        self::assertSame('', $config->serviceName);
        self::assertSame('', $config->serviceVersion);
        self::assertSame('', $config->serviceNamespace);
        self::assertSame(['tracecontext', 'baggage'], $config->propagators);
        self::assertFalse($config->dualExport);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = OpenTelemetryConfig::fromArray([
            'enabled' => true,
            'endpoint' => 'https://otel.example.com:4317',
            'protocol' => 'grpc',
            'timeout_ms' => 10000,
            'headers' => ['Authorization' => 'Bearer token'],
            'service_name' => 'my-service',
            'service_version' => '2.0.0',
            'service_namespace' => 'production',
            'propagators' => ['tracecontext'],
            'dual_export' => true,
            'traces' => ['enabled' => true],
            'metrics' => ['enabled' => false],
            'logs' => ['enabled' => true, 'min_level' => 'error'],
            'sampler' => ['type' => 'always'],
            'batch' => ['max_batch_size' => 256],
            'cardinality' => ['max_metric_series' => 500],
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('https://otel.example.com:4317', $config->endpoint);
        self::assertSame(OtlpProtocol::Grpc, $config->protocol);
        self::assertSame(10000, $config->timeoutMs);
        self::assertSame(['Authorization' => 'Bearer token'], $config->headers);
        self::assertSame('my-service', $config->serviceName);
        self::assertSame('2.0.0', $config->serviceVersion);
        self::assertSame('production', $config->serviceNamespace);
        self::assertSame(['tracecontext'], $config->propagators);
        self::assertTrue($config->dualExport);
        self::assertTrue($config->traces->enabled);
        self::assertFalse($config->metrics->enabled);
        self::assertTrue($config->logs->enabled);
        self::assertSame(SamplerType::Always, $config->sampler->type);
        self::assertSame(256, $config->batch->maxBatchSize);
        self::assertSame(500, $config->cardinality->maxMetricSeries);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = OpenTelemetryConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('http://localhost:4318', $config->endpoint);
        self::assertSame(OtlpProtocol::HttpProtobuf, $config->protocol);
    }

    #[Test]
    public function fromArrayWithInvalidTypesUsesDefaults(): void
    {
        $config = OpenTelemetryConfig::fromArray([
            'enabled' => 'yes',
            'endpoint' => 42,
            'protocol' => false,
            'timeout_ms' => 'slow',
            'headers' => 'not-array',
            'service_name' => 123,
            'service_version' => [],
            'service_namespace' => true,
            'propagators' => 'single',
            'dual_export' => 'on',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('http://localhost:4318', $config->endpoint);
        self::assertSame(OtlpProtocol::HttpProtobuf, $config->protocol);
        self::assertSame(5000, $config->timeoutMs);
        self::assertSame([], $config->headers);
        self::assertSame('', $config->serviceName);
        self::assertSame('', $config->serviceVersion);
        self::assertSame('', $config->serviceNamespace);
        self::assertSame(['tracecontext', 'baggage'], $config->propagators);
        self::assertFalse($config->dualExport);
    }

    #[Test]
    public function fromArrayWithInvalidProtocolStringFallsBackToDefault(): void
    {
        $config = OpenTelemetryConfig::fromArray([
            'protocol' => 'http/json',
        ]);

        self::assertSame(OtlpProtocol::HttpProtobuf, $config->protocol);
    }

    #[Test]
    public function fromArrayWithNonArraySubConfigsAreIgnored(): void
    {
        $config = OpenTelemetryConfig::fromArray([
            'traces' => 'enabled',
            'metrics' => false,
            'logs' => 42,
            'sampler' => 'always',
            'batch' => null,
            'cardinality' => 'auto',
        ]);

        // All sub-configs should use their own defaults
        self::assertTrue($config->traces->enabled);
        self::assertTrue($config->metrics->enabled);
        self::assertTrue($config->logs->enabled);
        self::assertSame(SamplerType::ParentBased, $config->sampler->type);
        self::assertSame(512, $config->batch->maxBatchSize);
        self::assertSame(2000, $config->cardinality->maxMetricSeries);
    }

    #[Test]
    public function headerInjectionIsRejected(): void
    {
        $env = $this->createEnvironmentStub([
            'OTEL_EXPORTER_OTLP_HEADERS' => "safe-key=safe-value,evil\rkey=val",
        ]);

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame(['safe-key' => 'safe-value'], $config->headers);
    }

    #[Test]
    public function envOverrideEndpoint(): void
    {
        $env = $this->createEnvironmentStub([
            'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://env.example.com:4317',
        ]);

        $config = OpenTelemetryConfig::fromArray([
            'endpoint' => 'https://file.example.com:4318',
        ], $env);

        self::assertSame('https://env.example.com:4317', $config->endpoint);
    }

    #[Test]
    public function envOverrideProtocol(): void
    {
        $env = $this->createEnvironmentStub([
            'OTEL_EXPORTER_OTLP_PROTOCOL' => 'grpc',
        ]);

        $config = OpenTelemetryConfig::fromArray([
            'protocol' => 'http/protobuf',
        ], $env);

        self::assertSame(OtlpProtocol::Grpc, $config->protocol);
    }

    #[Test]
    public function envOverrideProtocolInvalidKeepsFileValue(): void
    {
        $env = $this->createEnvironmentStub([
            'OTEL_EXPORTER_OTLP_PROTOCOL' => 'unknown_proto',
        ]);

        $config = OpenTelemetryConfig::fromArray([
            'protocol' => 'grpc',
        ], $env);

        self::assertSame(OtlpProtocol::Grpc, $config->protocol);
    }

    #[Test]
    public function envOverrideServiceName(): void
    {
        $env = $this->createEnvironmentStub([
            'OTEL_SERVICE_NAME' => 'env-service',
        ]);

        $config = OpenTelemetryConfig::fromArray([
            'service_name' => 'file-service',
        ], $env);

        self::assertSame('env-service', $config->serviceName);
    }

    #[Test]
    public function envOverrideSamplerAlwaysOn(): void
    {
        $env = $this->createEnvironmentStub([
            'OTEL_TRACES_SAMPLER' => 'always_on',
        ]);

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame(SamplerType::Always, $config->sampler->type);
    }

    #[Test]
    public function envOverrideSamplerAlwaysOff(): void
    {
        $env = $this->createEnvironmentStub([
            'OTEL_TRACES_SAMPLER' => 'always_off',
        ]);

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame(SamplerType::Never, $config->sampler->type);
    }

    #[Test]
    public function envOverrideSamplerTraceIdRatio(): void
    {
        $env = $this->createEnvironmentStub([
            'OTEL_TRACES_SAMPLER' => 'traceidratio',
            'OTEL_TRACES_SAMPLER_ARG' => '0.5',
        ]);

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame(SamplerType::Probability, $config->sampler->type);
        self::assertSame(0.5, $config->sampler->probability);
    }

    #[Test]
    public function envOverrideSamplerArgOutOfRangeKeepsDefault(): void
    {
        $env = $this->createEnvironmentStub([
            'OTEL_TRACES_SAMPLER' => 'traceidratio',
            'OTEL_TRACES_SAMPLER_ARG' => '2.0',
        ]);

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame(SamplerType::Probability, $config->sampler->type);
        self::assertSame(1.0, $config->sampler->probability);
    }

    #[Test]
    public function envOverrideParentBasedSampler(): void
    {
        $env = $this->createEnvironmentStub([
            'OTEL_TRACES_SAMPLER' => 'parentbased_always_on',
        ]);

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame(SamplerType::ParentBased, $config->sampler->type);
    }

    #[Test]
    public function envOverrideHeaders(): void
    {
        $env = $this->createEnvironmentStub([
            'OTEL_EXPORTER_OTLP_HEADERS' => 'key1=val1,key2=val2',
        ]);

        $config = OpenTelemetryConfig::fromArray([
            'headers' => ['old' => 'value'],
        ], $env);

        self::assertSame(['key1' => 'val1', 'key2' => 'val2'], $config->headers);
    }

    #[Test]
    public function envOverrideHeadersSkipsEmptyPairs(): void
    {
        $env = $this->createEnvironmentStub([
            'OTEL_EXPORTER_OTLP_HEADERS' => 'key1=val1,,key2=val2,',
        ]);

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame(['key1' => 'val1', 'key2' => 'val2'], $config->headers);
    }

    #[Test]
    public function envOverrideHeadersSkipsEntriesWithoutEquals(): void
    {
        $env = $this->createEnvironmentStub([
            'OTEL_EXPORTER_OTLP_HEADERS' => 'valid=yes,no-equals',
        ]);

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame(['valid' => 'yes'], $config->headers);
    }

    #[Test]
    public function envOverrideHeadersSkipsEmptyKeys(): void
    {
        $env = $this->createEnvironmentStub([
            'OTEL_EXPORTER_OTLP_HEADERS' => '=nokey,valid=yes',
        ]);

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame(['valid' => 'yes'], $config->headers);
    }

    #[Test]
    public function envOverrideHeadersNewlineInjectionRejected(): void
    {
        $env = $this->createEnvironmentStub([
            'OTEL_EXPORTER_OTLP_HEADERS' => "evil\nkey=val,good=ok",
        ]);

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame(['good' => 'ok'], $config->headers);
    }

    #[Test]
    public function envOverrideUnknownSamplerNameFallsThrough(): void
    {
        $env = $this->createEnvironmentStub([
            'OTEL_TRACES_SAMPLER' => 'nonexistent',
        ]);

        $config = OpenTelemetryConfig::fromArray([
            'sampler' => ['type' => 'always'],
        ], $env);

        // tryFrom('nonexistent') returns null, so file config preserved
        self::assertSame(SamplerType::Always, $config->sampler->type);
    }

    /**
     * @param array<string, string> $vars
     */
    private function createEnvironmentStub(array $vars): \Pulsar\Config\Environment
    {
        // Environment::load() reads OS vars. We use reflection to create
        // a controlled instance since the constructor is private.
        $ref = new ReflectionClass(\Pulsar\Config\Environment::class);
        $env = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('variables');
        $prop->setValue($env, $vars);

        return $env;
    }
}
