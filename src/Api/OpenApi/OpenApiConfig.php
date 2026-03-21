<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_string;

/**
 * Typed configuration DTO for OpenAPI spec generation.
 *
 * Controls the metadata, server URLs, security schemes, and output
 * settings for the generated OpenAPI specification.
 */
#[Api(since: '1.0.0')]
final readonly class OpenApiConfig
{
    /**
     * @param string $title API title in the info object
     * @param string $version API version string
     * @param string $description API description (supports CommonMark)
     * @param string|null $termsOfService URL to terms of service
     * @param string|null $contactName Contact name
     * @param string|null $contactEmail Contact email
     * @param string|null $contactUrl Contact URL
     * @param string|null $licenseName License name
     * @param string|null $licenseUrl License URL
     * @param list<array{url: string, description: string}> $servers Server definitions
     * @param list<SecuritySchemeDefinition> $securitySchemes Security scheme definitions
     * @param string $outputPath File path for the generated spec artifact
     * @param string $swaggerUiRoute Route path for serving Swagger UI
     * @param bool $swaggerUiEnabled Whether Swagger UI is enabled
     */
    public function __construct(
        public string $title = 'Pulsar API',
        public string $version = '1.0.0',
        public string $description = '',
        public ?string $termsOfService = null,
        public ?string $contactName = null,
        public ?string $contactEmail = null,
        public ?string $contactUrl = null,
        public ?string $licenseName = null,
        public ?string $licenseUrl = null,
        public array $servers = [],
        public array $securitySchemes = [],
        public string $outputPath = 'storage/api/openapi.json',
        public string $swaggerUiRoute = '/api/docs',
        public bool $swaggerUiEnabled = false,
    ) {}

    /**
     * Build an OpenApiConfig from a raw config array.
     *
     * @param array<string, mixed> $data Raw array from config/openapi.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawServers = $data['servers'] ?? [];
        $servers = is_array($rawServers) ? self::filterServers($rawServers) : [];

        $rawSchemes = $data['security_schemes'] ?? [];
        $securitySchemes = is_array($rawSchemes) ? self::filterSecuritySchemes($rawSchemes) : [];

        $rawOutputPath = $data['output_path'] ?? 'storage/api/openapi.json';
        $outputPath = is_string($rawOutputPath) ? $rawOutputPath : 'storage/api/openapi.json';

        $rawSwaggerRoute = $data['swagger_ui_route'] ?? '/api/docs';
        $swaggerUiRoute = is_string($rawSwaggerRoute) ? $rawSwaggerRoute : '/api/docs';

        $swaggerUiEnabled = ($data['swagger_ui_enabled'] ?? false) === true;

        return new self(
            title: self::stringOrDefault($data, 'title', 'Pulsar API'),
            version: self::stringOrDefault($data, 'version', '1.0.0'),
            description: self::stringOrDefault($data, 'description', ''),
            termsOfService: self::nullableString($data, 'terms_of_service'),
            contactName: self::nullableString($data, 'contact_name'),
            contactEmail: self::nullableString($data, 'contact_email'),
            contactUrl: self::nullableString($data, 'contact_url'),
            licenseName: self::nullableString($data, 'license_name'),
            licenseUrl: self::nullableString($data, 'license_url'),
            servers: $servers,
            securitySchemes: $securitySchemes,
            outputPath: $outputPath,
            swaggerUiRoute: $swaggerUiRoute,
            swaggerUiEnabled: $swaggerUiEnabled,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function stringOrDefault(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return list<array{url: string, description: string}>
     */
    private static function filterServers(array $items): array
    {
        $servers = [];

        foreach ($items as $item) {
            if (
                is_array($item)
                && isset($item['url'], $item['description'])
                && is_string($item['url'])
                && is_string($item['description'])
            ) {
                $servers[] = ['url' => $item['url'], 'description' => $item['description']];
            }
        }

        return $servers;
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return list<SecuritySchemeDefinition>
     */
    private static function filterSecuritySchemes(array $items): array
    {
        $schemes = [];

        foreach ($items as $item) {
            if ($item instanceof SecuritySchemeDefinition) {
                $schemes[] = $item;
            }
        }

        return $schemes;
    }
}
