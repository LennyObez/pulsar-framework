<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Tests\Unit\Interoperability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\DataAct\Config\DataActConfig;
use Pulsar\Extension\DataAct\Interoperability\InteroperabilityProfile;

#[CoversClass(InteroperabilityProfile::class)]
final class InteroperabilityProfileTest extends TestCase
{
    #[Test]
    public function constructionWithExplicitValues(): void
    {
        $profile = new InteroperabilityProfile(
            supportedFormats: ['json', 'xml'],
            apiStandards: ['openapi-3.1', 'graphql'],
            openInterfaces: ['rest-api', 'grpc'],
            complianceLevel: 'enhanced',
        );

        self::assertSame(['json', 'xml'], $profile->supportedFormats);
        self::assertSame(['openapi-3.1', 'graphql'], $profile->apiStandards);
        self::assertSame(['rest-api', 'grpc'], $profile->openInterfaces);
        self::assertSame('enhanced', $profile->complianceLevel);
    }

    #[Test]
    public function forPlatformWithDefaultConfig(): void
    {
        $config = new DataActConfig();
        $profile = InteroperabilityProfile::forPlatform($config);

        self::assertContains('json', $profile->supportedFormats);
        self::assertContains('csv', $profile->supportedFormats);
        self::assertContains('xml', $profile->supportedFormats);
        self::assertSame('basic', $profile->complianceLevel);
    }

    #[Test]
    public function forPlatformWithCloudProviderUsesEnhancedCompliance(): void
    {
        $config = new DataActConfig(entityRole: 'cloud_provider');
        $profile = InteroperabilityProfile::forPlatform($config);

        self::assertSame('enhanced', $profile->complianceLevel);
    }

    #[Test]
    public function forPlatformWithNonJsonDefaultIncludesBothFormats(): void
    {
        $config = new DataActConfig(defaultExportFormat: 'parquet');
        $profile = InteroperabilityProfile::forPlatform($config);

        self::assertContains('json', $profile->supportedFormats);
        self::assertContains('parquet', $profile->supportedFormats);
        self::assertContains('csv', $profile->supportedFormats);
    }

    #[Test]
    public function supportsFormatReturnsTrueForKnownFormat(): void
    {
        $profile = new InteroperabilityProfile(
            supportedFormats: ['json', 'csv'],
            apiStandards: [],
            openInterfaces: [],
        );

        self::assertTrue($profile->supportsFormat('json'));
        self::assertTrue($profile->supportsFormat('csv'));
        self::assertFalse($profile->supportsFormat('xml'));
    }

    #[Test]
    public function toArrayReturnsCompleteStructure(): void
    {
        $profile = new InteroperabilityProfile(
            supportedFormats: ['json'],
            apiStandards: ['openapi-3.1'],
            openInterfaces: ['rest-api'],
            complianceLevel: 'basic',
        );

        $array = $profile->toArray();

        self::assertSame(['json'], $array['supported_formats']);
        self::assertSame(['openapi-3.1'], $array['api_standards']);
        self::assertSame(['rest-api'], $array['open_interfaces']);
        self::assertSame('basic', $array['compliance_level']);
    }
}
