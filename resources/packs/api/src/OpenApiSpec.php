<?php

declare(strict_types=1);

namespace {{namespace}}\Http;

/**
 * OpenAPI specification generator stub.
 *
 * Provides a starting point for generating OpenAPI 3.1 specifications
 * for your API endpoints. Replace with your actual specification.
 */
final class OpenApiSpec
{
    /**
     * Generate the base OpenAPI specification array.
     *
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => '{{project_name}} API',
                'version' => '1.0.0',
                'description' => 'API documentation for {{project_name}}',
            ],
            'servers' => [
                [
                    'url' => '/api/v1',
                    'description' => 'API v1',
                ],
            ],
            'paths' => [
                '/health' => [
                    'get' => [
                        'summary' => 'Health check',
                        'operationId' => 'healthCheck',
                        'responses' => [
                            '200' => [
                                'description' => 'Service is healthy',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'status' => ['type' => 'string'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'securitySchemes' => [
                    'apiKey' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-API-Key',
                    ],
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                    ],
                ],
            ],
            'security' => [
                ['apiKey' => []],
            ],
        ];
    }

    /**
     * Return the spec as a JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->generate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
