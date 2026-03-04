<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\AppConfig;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\OpenTelemetry\Bridge\ResourceInfo;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo as ProtobufResourceInfo;

#[CoversClass(ResourceInfo::class)]
final class ResourceInfoTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $resource = new ResourceInfo(
            serviceName: 'my-service',
            serviceVersion: '1.2.3',
            deploymentEnvironment: 'production',
            serviceNamespace: 'backend',
            extraAttributes: ['custom' => 'attr'],
        );

        self::assertSame('my-service', $resource->serviceName);
        self::assertSame('1.2.3', $resource->serviceVersion);
        self::assertSame('production', $resource->deploymentEnvironment);
        self::assertSame('backend', $resource->serviceNamespace);
        self::assertSame(['custom' => 'attr'], $resource->extraAttributes);
    }

    #[Test]
    public function defaultValues(): void
    {
        $resource = new ResourceInfo(serviceName: 'svc');

        self::assertSame('', $resource->serviceVersion);
        self::assertSame('', $resource->deploymentEnvironment);
        self::assertSame('', $resource->serviceNamespace);
        self::assertSame([], $resource->extraAttributes);
    }

    #[Test]
    public function fromAppConfig(): void
    {
        $appConfig = new AppConfig(
            name: 'pulsar-app',
            mode: EnvironmentMode::Staging,
            debug: false,
            timezone: 'UTC',
            locale: 'en',
        );

        $resource = ResourceInfo::fromAppConfig($appConfig, '2.0.0', 'infra');

        self::assertSame('pulsar-app', $resource->serviceName);
        self::assertSame('2.0.0', $resource->serviceVersion);
        self::assertSame('staging', $resource->deploymentEnvironment);
        self::assertSame('infra', $resource->serviceNamespace);
    }

    #[Test]
    public function toProtobufMinimalAttributes(): void
    {
        $resource = new ResourceInfo(serviceName: 'test-svc');
        $pb = $resource->toProtobuf();

        self::assertInstanceOf(ProtobufResourceInfo::class, $pb);
        self::assertSame(['service.name' => 'test-svc'], $pb->attributes);
    }

    #[Test]
    public function toProtobufIncludesAllNonEmptyAttributes(): void
    {
        $resource = new ResourceInfo(
            serviceName: 'svc',
            serviceVersion: '1.0.0',
            deploymentEnvironment: 'prod',
            serviceNamespace: 'ns',
            extraAttributes: ['region' => 'us-east-1'],
        );

        $pb = $resource->toProtobuf();

        self::assertSame('svc', $pb->attributes['service.name']);
        self::assertSame('1.0.0', $pb->attributes['service.version']);
        self::assertSame('prod', $pb->attributes['deployment.environment']);
        self::assertSame('ns', $pb->attributes['service.namespace']);
        self::assertSame('us-east-1', $pb->attributes['region']);
    }

    #[Test]
    public function toProtobufOmitsEmptyVersion(): void
    {
        $resource = new ResourceInfo(
            serviceName: 'svc',
            serviceVersion: '',
        );

        $pb = $resource->toProtobuf();

        self::assertArrayNotHasKey('service.version', $pb->attributes);
    }

    #[Test]
    public function toProtobufOmitsEmptyEnvironment(): void
    {
        $resource = new ResourceInfo(
            serviceName: 'svc',
            deploymentEnvironment: '',
        );

        $pb = $resource->toProtobuf();

        self::assertArrayNotHasKey('deployment.environment', $pb->attributes);
    }

    #[Test]
    public function toProtobufOmitsEmptyNamespace(): void
    {
        $resource = new ResourceInfo(
            serviceName: 'svc',
            serviceNamespace: '',
        );

        $pb = $resource->toProtobuf();

        self::assertArrayNotHasKey('service.namespace', $pb->attributes);
    }
}
