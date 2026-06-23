<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function is_array;
use function is_string;

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
        return new self(
            title: Coerce::string($data['title'] ?? null, 'Pulsar API'),
            version: Coerce::string($data['version'] ?? null, '1.0.0'),
            description: Coerce::string($data['description'] ?? null),
            termsOfService: Coerce::nullableString($data['terms_of_service'] ?? null),
            contactName: Coerce::nullableString($data['contact_name'] ?? null),
            contactEmail: Coerce::nullableString($data['contact_email'] ?? null),
            contactUrl: Coerce::nullableString($data['contact_url'] ?? null),
            licenseName: Coerce::nullableString($data['license_name'] ?? null),
            licenseUrl: Coerce::nullableString($data['license_url'] ?? null),
            servers: self::filterServers($data['servers'] ?? null),
            securitySchemes: self::filterSecuritySchemes($data['security_schemes'] ?? null),
            outputPath: Coerce::string($data['output_path'] ?? null, 'storage/api/openapi.json'),
            swaggerUiRoute: Coerce::string($data['swagger_ui_route'] ?? null, '/api/docs'),
            swaggerUiEnabled: ($data['swagger_ui_enabled'] ?? false) === true,
        );
    }

    /**
     * @return list<array{url: string, description: string}>
     */
    private static function filterServers(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $servers = [];
        /** @var mixed $item */
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
     * @return list<SecuritySchemeDefinition>
     */
    private static function filterSecuritySchemes(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $schemes = [];
        /** @var mixed $item */
        foreach ($items as $item) {
            if ($item instanceof SecuritySchemeDefinition) {
                $schemes[] = $item;
            }
        }

        return $schemes;
    }
}
