<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Config\FhirConfig;
use Pulsar\Extension\Fhir\Resource\FhirVersion;

#[CoversClass(FhirConfig::class)]
final class FhirConfigTest extends TestCase
{
    #[Test]
    public function defaultsUseR4AndStandardResourceTypes(): void
    {
        $config = new FhirConfig();

        self::assertSame('Pulsar FHIR Server', $config->serverName);
        self::assertSame(FhirVersion::R4, $config->fhirVersion);
        self::assertSame('/fhir', $config->basePath);
        self::assertCount(8, $config->supportedResourceTypes);
        self::assertContains('Patient', $config->supportedResourceTypes);
        self::assertContains('Observation', $config->supportedResourceTypes);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = FhirConfig::fromArray([
            'server_name' => 'Hospital FHIR',
            'fhir_version' => '5.0.0',
            'base_path' => '/api/fhir',
            'supported_resource_types' => ['Patient', 'Observation'],
        ]);

        self::assertSame('Hospital FHIR', $config->serverName);
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
    public function fromArrayIgnoresNonStringFields(): void
    {
        $config = FhirConfig::fromArray([
            'server_name' => 42,
            'base_path' => false,
        ]);

        self::assertSame('Pulsar FHIR Server', $config->serverName);
        self::assertSame('/fhir', $config->basePath);
    }
}
