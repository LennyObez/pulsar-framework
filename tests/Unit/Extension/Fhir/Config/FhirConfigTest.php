<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Fhir\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Config\FhirConfig;
use Pulsar\Extension\Fhir\Resource\FhirVersion;

#[CoversClass(FhirConfig::class)]
final class FhirConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreSensible(): void
    {
        $config = new FhirConfig();

        self::assertSame('Pulsar FHIR Server', $config->serverName);
        self::assertSame(FhirVersion::R4, $config->fhirVersion);
        self::assertSame('/fhir', $config->basePath);
        self::assertContains('Patient', $config->supportedResourceTypes);
        self::assertContains('Observation', $config->supportedResourceTypes);
        self::assertCount(8, $config->supportedResourceTypes);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = FhirConfig::fromArray([
            'server_name' => 'Custom FHIR',
            'fhir_version' => '5.0.0',
            'base_path' => '/api/fhir',
            'supported_resource_types' => ['Patient', 'Observation'],
        ]);

        self::assertSame('Custom FHIR', $config->serverName);
        self::assertSame(FhirVersion::R5, $config->fhirVersion);
        self::assertSame('/api/fhir', $config->basePath);
        self::assertSame(['Patient', 'Observation'], $config->supportedResourceTypes);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = FhirConfig::fromArray([]);

        self::assertSame('Pulsar FHIR Server', $config->serverName);
        self::assertSame(FhirVersion::R4, $config->fhirVersion);
        self::assertSame('/fhir', $config->basePath);
        self::assertCount(8, $config->supportedResourceTypes);
    }

    #[Test]
    public function fromArrayIgnoresNonStringServerName(): void
    {
        $config = FhirConfig::fromArray([
            'server_name' => 42,
        ]);

        self::assertSame('Pulsar FHIR Server', $config->serverName);
    }

    #[Test]
    public function fromArrayIgnoresNonStringVersion(): void
    {
        $config = FhirConfig::fromArray([
            'fhir_version' => null,
        ]);

        self::assertSame(FhirVersion::R4, $config->fhirVersion);
    }

    #[Test]
    public function fromArrayIgnoresNonStringBasePath(): void
    {
        $config = FhirConfig::fromArray([
            'base_path' => ['invalid'],
        ]);

        self::assertSame('/fhir', $config->basePath);
    }
}
