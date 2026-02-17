<?php

declare(strict_types=1);

/**
 * OpenAPI Specification Configuration
 *
 * Controls the generated OpenAPI spec metadata, security schemes,
 * server URLs, and Swagger UI settings.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | API Info
    |--------------------------------------------------------------------------
    |
    | Title, version, and description emitted in the OpenAPI info object.
    |
    */
    'title' => 'Pulsar API',
    'version' => '1.0.0',
    'description' => '',

    /*
    |--------------------------------------------------------------------------
    | Contact & License
    |--------------------------------------------------------------------------
    */
    'contact_name' => null,
    'contact_email' => null,
    'contact_url' => null,
    'license_name' => null,
    'license_url' => null,
    'terms_of_service' => null,

    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    |
    | Server definitions included in the spec. Each entry needs:
    |   'url'         — base URL (e.g., 'https://api.example.com')
    |   'description' — human-readable label
    |
    */
    'servers' => [
        // ['url' => 'https://api.example.com', 'description' => 'Production'],
        // ['url' => 'https://staging-api.example.com', 'description' => 'Staging'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Schemes
    |--------------------------------------------------------------------------
    |
    | Instances of SecuritySchemeDefinition. Use the static factory methods:
    |
    |   SecuritySchemeDefinition::bearer()
    |   SecuritySchemeDefinition::apiKey('apiKeyAuth', 'X-API-Key')
    |   SecuritySchemeDefinition::oauth2($authUrl, $tokenUrl, $scopes)
    |
    */
    'security_schemes' => [
        // \Pulsar\Api\OpenApi\SecuritySchemeDefinition::bearer(),
    ],

    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    |
    | File path for the generated spec artifact (relative to project root).
    |
    */
    'output_path' => 'storage/api/openapi.json',

    /*
    |--------------------------------------------------------------------------
    | Swagger UI
    |--------------------------------------------------------------------------
    |
    | Enable to register routes that serve a Swagger UI page and the
    | pre-built spec file. Disabled by default — enable for non-production
    | environments or behind an authentication middleware.
    |
    */
    'swagger_ui_enabled' => false,
    'swagger_ui_route' => '/api/docs',
];
