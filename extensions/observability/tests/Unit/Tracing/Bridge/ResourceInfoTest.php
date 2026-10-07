<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Tracing\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\AppConfig;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\ResourceInfo as ProtobufResourceInfo;
use Pulsar\Extension\Observability\Tracing\Bridge\ResourceInfo;

#[CoversClass(ResourceInfo::class)]
final class ResourceInfoTest extends TestCase
{
    #[Test]
    public function constructionWithAllFields(): void
    {
        $resource = new ResourceInfo(
            serviceName: 'banking-api',
            serviceVersion: '2.4.1',
            deploymentEnvironment: 'production',
            serviceNamespace: 'financial-services',
            extraAttributes: ['host.name' => 'api-node-03'],
        );

        self::assertSame('banking-api', $resource->serviceName);
        self::assertSame('2.4.1', $resource->serviceVersion);
        self::assertSame('production', $resource->deploymentEnvironment);
        self::assertSame('financial-services', $resource->serviceNamespace);
        self::assertSame('api-node-03', $resource->extraAttributes['host.name']);
    }

    #[Test]
    public function constructionDefaults(): void
    {
        $resource = new ResourceInfo(serviceName: 'minimal-service');

        self::assertSame('minimal-service', $resource->serviceName);
        self::assertSame('', $resource->serviceVersion);
        self::assertSame('', $resource->deploymentEnvironment);
        self::assertSame('', $resource->serviceNamespace);
        self::assertSame([], $resource->extraAttributes);
    }

    #[Test]
    public function fromAppConfig(): void
    {
        $appConfig = new AppConfig(
            name: 'pulsar-banking',
            mode: EnvironmentMode::Production,
            debug: false,
            timezone: 'UTC',
            locale: 'en',
        );

        $resource = ResourceInfo::fromAppConfig($appConfig, '1.0.0-rc.11', 'core');

        self::assertSame('pulsar-banking', $resource->serviceName);
        self::assertSame('1.0.0-rc.11', $resource->serviceVersion);
        self::assertSame('production', $resource->deploymentEnvironment);
        self::assertSame('core', $resource->serviceNamespace);
    }

    #[Test]
    public function toProtobufMinimal(): void
    {
        $resource = new ResourceInfo(serviceName: 'test-service');

        $protobuf = $resource->toProtobuf();

        self::assertInstanceOf(ProtobufResourceInfo::class, $protobuf);
        self::assertSame('test-service', $protobuf->attributes['service.name']);
        self::assertArrayNotHasKey('service.version', $protobuf->attributes);
        self::assertArrayNotHasKey('deployment.environment', $protobuf->attributes);
    }

    #[Test]
    public function toProtobufFull(): void
    {
        $resource = new ResourceInfo(
            serviceName: 'compliance-api',
            serviceVersion: '3.0.0',
            deploymentEnvironment: 'staging',
            serviceNamespace: 'compliance',
            extraAttributes: ['cloud.region' => 'us-east-1'],
        );

        $protobuf = $resource->toProtobuf();

        self::assertSame('compliance-api', $protobuf->attributes['service.name']);
        self::assertSame('3.0.0', $protobuf->attributes['service.version']);
        self::assertSame('staging', $protobuf->attributes['deployment.environment']);
        self::assertSame('compliance', $protobuf->attributes['service.namespace']);
        self::assertSame('us-east-1', $protobuf->attributes['cloud.region']);
    }
}
