<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;

/**
 * Typed configuration DTO for OpenAPI spec generation.
 *
 * Controls the metadata, server URLs, security schemes, and output
 * settings for the generated OpenAPI specification.
 * @api
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
     * @param array{
     *     title?: string,
     *     version?: string,
     *     description?: string,
     *     terms_of_service?: string|null,
     *     contact_name?: string|null,
     *     contact_email?: string|null,
     *     contact_url?: string|null,
     *     license_name?: string|null,
     *     license_url?: string|null,
     *     servers?: list<array{url: string, description: string}>,
     *     security_schemes?: list<SecuritySchemeDefinition>,
     *     output_path?: string,
     *     swagger_ui_route?: string,
     *     swagger_ui_enabled?: bool,
     * } $data Raw array from config/openapi.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $servers = self::filterServers($data['servers'] ?? []);
        $securitySchemes = self::filterSecuritySchemes($data['security_schemes'] ?? []);

        return new self(
            title: $data['title'] ?? 'Pulsar API',
            version: $data['version'] ?? '1.0.0',
            description: $data['description'] ?? '',
            termsOfService: $data['terms_of_service'] ?? null,
            contactName: $data['contact_name'] ?? null,
            contactEmail: $data['contact_email'] ?? null,
            contactUrl: $data['contact_url'] ?? null,
            licenseName: $data['license_name'] ?? null,
            licenseUrl: $data['license_url'] ?? null,
            servers: $servers,
            securitySchemes: $securitySchemes,
            outputPath: $data['output_path'] ?? 'storage/api/openapi.json',
            swaggerUiRoute: $data['swagger_ui_route'] ?? '/api/docs',
            swaggerUiEnabled: ($data['swagger_ui_enabled'] ?? false) === true,
        );
    }

    /**
     * @param list<array{url: string, description: string}> $items
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
            ) {
                $servers[] = ['url' => $item['url'], 'description' => $item['description']];
            }
        }

        return $servers;
    }

    /**
     * @param list<SecuritySchemeDefinition> $items
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
