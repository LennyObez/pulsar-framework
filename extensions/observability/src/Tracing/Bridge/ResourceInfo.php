<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tracing\Bridge;

use Pulsar\Api\Api;
use Pulsar\Config\AppConfig;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\ResourceInfo as ProtobufResourceInfo;

use function array_merge;

/**
 * Resource information identifying the entity producing telemetry.
 *
 * Maps to the OpenTelemetry Resource semantic conventions. Converts
 * to the internal protobuf ResourceInfo for OTLP export.
 */
#[Api(since: '1.0.0')]
final readonly class ResourceInfo
{
    /**
     * @param array<string, scalar> $extraAttributes Additional resource attributes
     */
    public function __construct(
        public string $serviceName,
        public string $serviceVersion = '',
        public string $deploymentEnvironment = '',
        public string $serviceNamespace = '',
        public array $extraAttributes = [],
    ) {}

    /**
     * Build ResourceInfo from application config.
     */
    public static function fromAppConfig(
        AppConfig $appConfig,
        string $serviceVersion = '',
        string $serviceNamespace = '',
    ): self {
        return new self(
            serviceName: $appConfig->name,
            serviceVersion: $serviceVersion,
            deploymentEnvironment: $appConfig->mode->value,
            serviceNamespace: $serviceNamespace,
        );
    }

    /**
     * Convert to the internal protobuf ResourceInfo for OTLP serialization.
     */
    public function toProtobuf(): ProtobufResourceInfo
    {
        $attributes = ['service.name' => $this->serviceName];

        if ($this->serviceVersion !== '') {
            $attributes['service.version'] = $this->serviceVersion;
        }

        if ($this->deploymentEnvironment !== '') {
            $attributes['deployment.environment'] = $this->deploymentEnvironment;
        }

        if ($this->serviceNamespace !== '') {
            $attributes['service.namespace'] = $this->serviceNamespace;
        }

        return new ProtobufResourceInfo(
            attributes: array_merge($attributes, $this->extraAttributes),
        );
    }
}
