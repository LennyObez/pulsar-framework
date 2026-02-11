<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Resource\ComplexityLimits;
use Pulsar\Config\ApiConfig;

#[CoversClass(ApiConfig::class)]
final class ApiConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithEmptyArrayReturnsDefaults(): void
    {
        $config = ApiConfig::fromArray([]);

        self::assertSame('json', $config->defaultFormat);
        self::assertSame('offset', $config->paginationType);
        self::assertSame(25, $config->paginationDefaultSize);
        self::assertSame(100, $config->paginationMaxSize);
        self::assertSame('url', $config->versioningStrategy);
        self::assertInstanceOf(ComplexityLimits::class, $config->complexityLimits);
        self::assertSame(50, $config->complexityLimits->maxFields);
        self::assertSame(3, $config->complexityLimits->maxNestingDepth);
        self::assertSame(10, $config->complexityLimits->maxIncludes);
        self::assertTrue($config->entitySerializationBanEnabled);
    }

    #[Test]
    public function fromArrayWithFullConfig(): void
    {
        $config = ApiConfig::fromArray([
            'default_format' => 'xml',
            'pagination' => [
                'type' => 'cursor',
                'default_size' => 50,
                'max_size' => 200,
            ],
            'versioning_strategy' => 'header',
            'complexity_limits' => [
                'max_fields' => 100,
                'max_nesting_depth' => 5,
                'max_includes' => 20,
            ],
            'entity_serialization_ban' => false,
        ]);

        self::assertSame('xml', $config->defaultFormat);
        self::assertSame('cursor', $config->paginationType);
        self::assertSame(50, $config->paginationDefaultSize);
        self::assertSame(200, $config->paginationMaxSize);
        self::assertSame('header', $config->versioningStrategy);
        self::assertSame(100, $config->complexityLimits->maxFields);
        self::assertSame(5, $config->complexityLimits->maxNestingDepth);
        self::assertSame(20, $config->complexityLimits->maxIncludes);
        self::assertFalse($config->entitySerializationBanEnabled);
    }

    #[Test]
    public function fromArrayHandlesNonStringDefaultFormat(): void
    {
        $config = ApiConfig::fromArray([
            'default_format' => 42,
        ]);

        self::assertSame('json', $config->defaultFormat);
    }

    #[Test]
    public function fromArrayHandlesNonStringVersioningStrategy(): void
    {
        $config = ApiConfig::fromArray([
            'versioning_strategy' => ['url'],
        ]);

        self::assertSame('url', $config->versioningStrategy);
    }

    #[Test]
    public function fromArrayHandlesNonArrayPagination(): void
    {
        $config = ApiConfig::fromArray([
            'pagination' => 'invalid',
        ]);

        self::assertSame('offset', $config->paginationType);
        self::assertSame(25, $config->paginationDefaultSize);
        self::assertSame(100, $config->paginationMaxSize);
    }

    #[Test]
    public function fromArrayHandlesNonIntPaginationSizes(): void
    {
        $config = ApiConfig::fromArray([
            'pagination' => [
                'type' => 123,
                'default_size' => 'ten',
                'max_size' => '200',
            ],
        ]);

        self::assertSame('offset', $config->paginationType);
        self::assertSame(25, $config->paginationDefaultSize);
        self::assertSame(100, $config->paginationMaxSize);
    }

    #[Test]
    public function fromArrayHandlesNonArrayComplexityLimits(): void
    {
        $config = ApiConfig::fromArray([
            'complexity_limits' => 'not_an_array',
        ]);

        self::assertSame(50, $config->complexityLimits->maxFields);
        self::assertSame(3, $config->complexityLimits->maxNestingDepth);
        self::assertSame(10, $config->complexityLimits->maxIncludes);
    }

    #[Test]
    public function fromArrayEntitySerializationBanDefaultsToTrue(): void
    {
        $config = ApiConfig::fromArray([]);

        self::assertTrue($config->entitySerializationBanEnabled);
    }

    #[Test]
    public function fromArrayEntitySerializationBanCoercesTruthy(): void
    {
        $config = ApiConfig::fromArray(['entity_serialization_ban' => 1]);

        self::assertTrue($config->entitySerializationBanEnabled);
    }

    #[Test]
    public function fromArrayEntitySerializationBanCoercesFalsy(): void
    {
        $config = ApiConfig::fromArray(['entity_serialization_ban' => 0]);

        self::assertFalse($config->entitySerializationBanEnabled);
    }

    #[Test]
    public function fromArrayWithNullValues(): void
    {
        $config = ApiConfig::fromArray([
            'default_format' => null,
            'pagination' => null,
            'versioning_strategy' => null,
            'complexity_limits' => null,
            'entity_serialization_ban' => null,
        ]);

        self::assertSame('json', $config->defaultFormat);
        self::assertSame('offset', $config->paginationType);
        self::assertSame(25, $config->paginationDefaultSize);
        self::assertSame(100, $config->paginationMaxSize);
        self::assertSame('url', $config->versioningStrategy);
        // null ?? true yields true, so (bool) true = true
        self::assertTrue($config->entitySerializationBanEnabled);
    }

    #[Test]
    public function fromArrayWithPartialPagination(): void
    {
        $config = ApiConfig::fromArray([
            'pagination' => [
                'type' => 'cursor',
            ],
        ]);

        self::assertSame('cursor', $config->paginationType);
        self::assertSame(25, $config->paginationDefaultSize);
        self::assertSame(100, $config->paginationMaxSize);
    }

    #[Test]
    public function fromArrayWithPartialComplexityLimits(): void
    {
        $config = ApiConfig::fromArray([
            'complexity_limits' => [
                'max_fields' => 75,
            ],
        ]);

        self::assertSame(75, $config->complexityLimits->maxFields);
        self::assertSame(3, $config->complexityLimits->maxNestingDepth);
        self::assertSame(10, $config->complexityLimits->maxIncludes);
    }

    /**
     * @return iterable<string, array{string, string, int, int}>
     */
    public static function paginationConfigProvider(): iterable
    {
        yield 'offset defaults' => ['offset', 'offset', 25, 100];
        yield 'cursor with sizes' => ['cursor', 'cursor', 50, 200];
        yield 'keyset style' => ['keyset', 'keyset', 10, 50];
    }

    #[Test]
    #[DataProvider('paginationConfigProvider')]
    public function fromArrayHandlesVariousPaginationConfigs(
        string $type,
        string $expectedType,
        int $defaultSize,
        int $maxSize,
    ): void {
        $config = ApiConfig::fromArray([
            'pagination' => [
                'type' => $type,
                'default_size' => $defaultSize,
                'max_size' => $maxSize,
            ],
        ]);

        self::assertSame($expectedType, $config->paginationType);
        self::assertSame($defaultSize, $config->paginationDefaultSize);
        self::assertSame($maxSize, $config->paginationMaxSize);
    }

    #[Test]
    public function constructorStoresAllProperties(): void
    {
        $limits = new ComplexityLimits(maxFields: 30, maxNestingDepth: 2, maxIncludes: 5);

        $config = new ApiConfig(
            defaultFormat: 'hal+json',
            paginationType: 'keyset',
            paginationDefaultSize: 15,
            paginationMaxSize: 50,
            versioningStrategy: 'media-type',
            complexityLimits: $limits,
            entitySerializationBanEnabled: false,
        );

        self::assertSame('hal+json', $config->defaultFormat);
        self::assertSame('keyset', $config->paginationType);
        self::assertSame(15, $config->paginationDefaultSize);
        self::assertSame(50, $config->paginationMaxSize);
        self::assertSame('media-type', $config->versioningStrategy);
        self::assertSame($limits, $config->complexityLimits);
        self::assertFalse($config->entitySerializationBanEnabled);
    }
}
