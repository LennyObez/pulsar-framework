<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\OpenApi\OpenApiConfig;
use Pulsar\Api\OpenApi\SecuritySchemeDefinition;

#[CoversClass(OpenApiConfig::class)]
final class OpenApiConfigTest extends TestCase
{
    // --- Constructor defaults ---

    #[Test]
    public function constructorDefaultValues(): void
    {
        $config = new OpenApiConfig();

        self::assertSame('Pulsar API', $config->title);
        self::assertSame('1.0.0', $config->version);
        self::assertSame('', $config->description);
        self::assertNull($config->termsOfService);
        self::assertNull($config->contactName);
        self::assertNull($config->contactEmail);
        self::assertNull($config->contactUrl);
        self::assertNull($config->licenseName);
        self::assertNull($config->licenseUrl);
        self::assertSame([], $config->servers);
        self::assertSame([], $config->securitySchemes);
        self::assertSame('storage/api/openapi.json', $config->outputPath);
        self::assertSame('/api/docs', $config->swaggerUiRoute);
        self::assertFalse($config->swaggerUiEnabled);
    }

    // --- fromArray: string fields ---

    #[Test]
    public function fromArraySetsStringFields(): void
    {
        $config = OpenApiConfig::fromArray([
            'title' => 'My API',
            'version' => '2.0.0',
            'description' => 'My description',
        ]);

        self::assertSame('My API', $config->title);
        self::assertSame('2.0.0', $config->version);
        self::assertSame('My description', $config->description);
    }

    #[Test]
    public function fromArrayDefaultsForMissingKeys(): void
    {
        $config = OpenApiConfig::fromArray([]);

        self::assertSame('Pulsar API', $config->title);
        self::assertSame('1.0.0', $config->version);
        self::assertSame('', $config->description);
    }

    #[Test]
    public function fromArrayDefaultsForNonStringValues(): void
    {
        $config = OpenApiConfig::fromArray([
            'title' => 123,
            'version' => true,
            'description' => null,
        ]);

        self::assertSame('Pulsar API', $config->title);
        self::assertSame('1.0.0', $config->version);
        self::assertSame('', $config->description);
    }

    // --- fromArray: nullable string fields ---

    #[Test]
    public function fromArraySetsNullableStringFields(): void
    {
        $config = OpenApiConfig::fromArray([
            'terms_of_service' => 'https://example.com/tos',
            'contact_name' => 'John Doe',
            'contact_email' => 'john@example.com',
            'contact_url' => 'https://example.com',
            'license_name' => 'MIT',
            'license_url' => 'https://opensource.org/licenses/MIT',
        ]);

        self::assertSame('https://example.com/tos', $config->termsOfService);
        self::assertSame('John Doe', $config->contactName);
        self::assertSame('john@example.com', $config->contactEmail);
        self::assertSame('https://example.com', $config->contactUrl);
        self::assertSame('MIT', $config->licenseName);
        self::assertSame('https://opensource.org/licenses/MIT', $config->licenseUrl);
    }

    #[Test]
    public function fromArrayNullableStringFieldsReturnNullForNonString(): void
    {
        $config = OpenApiConfig::fromArray([
            'terms_of_service' => 42,
            'contact_name' => false,
            'contact_email' => [],
        ]);

        self::assertNull($config->termsOfService);
        self::assertNull($config->contactName);
        self::assertNull($config->contactEmail);
    }

    #[Test]
    public function fromArrayNullableStringFieldsReturnNullWhenMissing(): void
    {
        $config = OpenApiConfig::fromArray([]);

        self::assertNull($config->termsOfService);
        self::assertNull($config->contactName);
        self::assertNull($config->contactEmail);
        self::assertNull($config->contactUrl);
        self::assertNull($config->licenseName);
        self::assertNull($config->licenseUrl);
    }

    // --- fromArray: servers ---

    #[Test]
    public function fromArrayFiltersValidServers(): void
    {
        $config = OpenApiConfig::fromArray([
            'servers' => [
                ['url' => 'https://api.example.com', 'description' => 'Production'],
                ['url' => 'https://staging.example.com', 'description' => 'Staging'],
            ],
        ]);

        self::assertCount(2, $config->servers);
        self::assertSame('https://api.example.com', $config->servers[0]['url']);
        self::assertSame('Production', $config->servers[0]['description']);
        self::assertSame('Staging', $config->servers[1]['description']);
    }

    #[Test]
    public function fromArrayFiltersOutInvalidServerEntries(): void
    {
        $config = OpenApiConfig::fromArray([
            'servers' => [
                ['url' => 'https://valid.com', 'description' => 'Valid'],
                ['url' => 123, 'description' => 'Invalid URL type'],
                ['description' => 'Missing URL'],
                'not-an-array',
                ['url' => 'https://noDesc.com'],
                ['url' => 'https://also-valid.com', 'description' => 'Also valid'],
            ],
        ]);

        self::assertCount(2, $config->servers);
        self::assertSame('https://valid.com', $config->servers[0]['url']);
        self::assertSame('https://also-valid.com', $config->servers[1]['url']);
    }

    #[Test]
    public function fromArrayServersDefaultsToEmptyWhenNotArray(): void
    {
        $config = OpenApiConfig::fromArray([
            'servers' => 'not-an-array',
        ]);

        self::assertSame([], $config->servers);
    }

    #[Test]
    public function fromArrayServersDefaultsToEmptyWhenMissing(): void
    {
        $config = OpenApiConfig::fromArray([]);

        self::assertSame([], $config->servers);
    }

    // --- fromArray: security schemes ---

    #[Test]
    public function fromArrayFiltersSecuritySchemeDefinitions(): void
    {
        $bearer = SecuritySchemeDefinition::bearer();
        $apiKey = SecuritySchemeDefinition::apiKey();

        $config = OpenApiConfig::fromArray([
            'security_schemes' => [$bearer, $apiKey],
        ]);

        self::assertCount(2, $config->securitySchemes);
        self::assertSame($bearer, $config->securitySchemes[0]);
        self::assertSame($apiKey, $config->securitySchemes[1]);
    }

    #[Test]
    public function fromArrayFiltersOutNonSecuritySchemeObjects(): void
    {
        $bearer = SecuritySchemeDefinition::bearer();

        $config = OpenApiConfig::fromArray([
            'security_schemes' => [
                $bearer,
                'not-a-scheme',
                42,
                null,
                new \stdClass(),
            ],
        ]);

        self::assertCount(1, $config->securitySchemes);
        self::assertSame($bearer, $config->securitySchemes[0]);
    }

    #[Test]
    public function fromArraySecuritySchemesDefaultsToEmptyWhenNotArray(): void
    {
        $config = OpenApiConfig::fromArray([
            'security_schemes' => 'not-an-array',
        ]);

        self::assertSame([], $config->securitySchemes);
    }

    // --- fromArray: output path ---

    #[Test]
    public function fromArraySetsCustomOutputPath(): void
    {
        $config = OpenApiConfig::fromArray([
            'output_path' => 'build/api-spec.json',
        ]);

        self::assertSame('build/api-spec.json', $config->outputPath);
    }

    #[Test]
    public function fromArrayOutputPathDefaultsForNonString(): void
    {
        $config = OpenApiConfig::fromArray([
            'output_path' => 42,
        ]);

        self::assertSame('storage/api/openapi.json', $config->outputPath);
    }

    // --- fromArray: swagger UI ---

    #[Test]
    public function fromArraySetsSwaggerUiRoute(): void
    {
        $config = OpenApiConfig::fromArray([
            'swagger_ui_route' => '/docs/api',
        ]);

        self::assertSame('/docs/api', $config->swaggerUiRoute);
    }

    #[Test]
    public function fromArraySwaggerUiRouteDefaultsForNonString(): void
    {
        $config = OpenApiConfig::fromArray([
            'swagger_ui_route' => false,
        ]);

        self::assertSame('/api/docs', $config->swaggerUiRoute);
    }

    #[Test]
    public function fromArraySwaggerUiEnabledTrue(): void
    {
        $config = OpenApiConfig::fromArray([
            'swagger_ui_enabled' => true,
        ]);

        self::assertTrue($config->swaggerUiEnabled);
    }

    #[Test]
    public function fromArraySwaggerUiEnabledFalseByDefault(): void
    {
        $config = OpenApiConfig::fromArray([]);

        self::assertFalse($config->swaggerUiEnabled);
    }

    #[Test]
    #[DataProvider('nonTrueBooleanValueProvider')]
    public function fromArraySwaggerUiEnabledRejectsTruthyNonTrue(mixed $value): void
    {
        $config = OpenApiConfig::fromArray([
            'swagger_ui_enabled' => $value,
        ]);

        self::assertFalse($config->swaggerUiEnabled);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonTrueBooleanValueProvider(): iterable
    {
        yield 'integer 1' => [1];
        yield 'string true' => ['true'];
        yield 'string 1' => ['1'];
        yield 'false' => [false];
    }

    // --- Full config from array ---

    #[Test]
    public function fromArrayComprehensive(): void
    {
        $bearer = SecuritySchemeDefinition::bearer();
        $config = OpenApiConfig::fromArray([
            'title' => 'Full API',
            'version' => '3.0.0',
            'description' => 'Comprehensive test',
            'terms_of_service' => 'https://example.com/terms',
            'contact_name' => 'Support',
            'contact_email' => 'support@example.com',
            'contact_url' => 'https://example.com/support',
            'license_name' => 'Apache 2.0',
            'license_url' => 'https://apache.org/licenses/LICENSE-2.0',
            'servers' => [
                ['url' => 'https://api.example.com', 'description' => 'Live'],
            ],
            'security_schemes' => [$bearer],
            'output_path' => 'dist/openapi.json',
            'swagger_ui_route' => '/swagger',
            'swagger_ui_enabled' => true,
        ]);

        self::assertSame('Full API', $config->title);
        self::assertSame('3.0.0', $config->version);
        self::assertSame('Comprehensive test', $config->description);
        self::assertSame('https://example.com/terms', $config->termsOfService);
        self::assertSame('Support', $config->contactName);
        self::assertSame('support@example.com', $config->contactEmail);
        self::assertSame('https://example.com/support', $config->contactUrl);
        self::assertSame('Apache 2.0', $config->licenseName);
        self::assertSame('https://apache.org/licenses/LICENSE-2.0', $config->licenseUrl);
        self::assertCount(1, $config->servers);
        self::assertCount(1, $config->securitySchemes);
        self::assertSame('dist/openapi.json', $config->outputPath);
        self::assertSame('/swagger', $config->swaggerUiRoute);
        self::assertTrue($config->swaggerUiEnabled);
    }
}
