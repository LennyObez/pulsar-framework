<?php

declare(strict_types=1);

namespace Pulsar\Api\OpenApi;

use Pulsar\Api\Api;
use Pulsar\Api\OpenApi\Attribute\ApiParam;
use Pulsar\Api\OpenApi\Attribute\ApiResponse;

use function array_key_exists;
use function array_keys;
use function array_map;
use function in_array;
use function json_encode;
use function ksort;
use function preg_match_all;
use function str_replace;
use function strtolower;
use function strtoupper;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Build-time OpenAPI v3.1 specification generator.
 *
 * Takes pre-scanned endpoint metadata and produces a complete OpenAPI
 * specification array. No runtime reflection is performed; all type
 * information must be provided via `EndpointMetadata` instances
 * collected at build time.
 * @api
 */
#[Api(since: '1.0.0')]
final class SpecGenerator
{
    /** @var array<string, array<string, mixed>> Collected component schemas */
    private array $schemas = [];

    public function __construct(
        private readonly OpenApiConfig $config,
        private readonly SchemaInferrer $schemaInferrer,
    ) {}

    /**
     * Generate a complete OpenAPI v3.1 specification.
     *
     * @param list<EndpointMetadata> $endpoints Pre-scanned endpoint metadata
     *
     * @return array<string, mixed> Complete OpenAPI specification array
     */
    public function generate(array $endpoints): array
    {
        $this->schemas = [];

        $spec = [
            'openapi' => '3.1.0',
            'info' => $this->buildInfo(),
        ];

        if ($this->config->servers !== []) {
            $spec['servers'] = $this->config->servers;
        }

        $paths = $this->buildPaths($endpoints);
        ksort($paths);
        $spec['paths'] = $paths;

        $components = $this->buildComponents();
        if ($components !== []) {
            $spec['components'] = $components;
        }

        $globalSecurity = $this->buildGlobalSecurity();
        if ($globalSecurity !== []) {
            $spec['security'] = $globalSecurity;
        }

        $tags = $this->collectTags($endpoints);
        if ($tags !== []) {
            $spec['tags'] = $tags;
        }

        return $spec;
    }

    /**
     * Generate the specification as a JSON string.
     *
     * @param list<EndpointMetadata> $endpoints Pre-scanned endpoint metadata
     *
     * @return string JSON-encoded OpenAPI specification
     */
    public function generateJson(array $endpoints): string
    {
        $spec = $this->generate($endpoints);

        /** @var non-empty-string */
        return json_encode(
            $spec,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Build the OpenAPI info object.
     *
     * @return array<string, mixed>
     */
    private function buildInfo(): array
    {
        $info = [
            'title' => $this->config->title,
            'version' => $this->config->version,
        ];

        if ($this->config->description !== '') {
            $info['description'] = $this->config->description;
        }

        if ($this->config->termsOfService !== null) {
            $info['termsOfService'] = $this->config->termsOfService;
        }

        $contact = $this->buildContact();
        if ($contact !== []) {
            $info['contact'] = $contact;
        }

        $license = $this->buildLicense();
        if ($license !== []) {
            $info['license'] = $license;
        }

        return $info;
    }

    /**
     * @return array<string, string>
     */
    private function buildContact(): array
    {
        $contact = [];

        if ($this->config->contactName !== null) {
            $contact['name'] = $this->config->contactName;
        }

        if ($this->config->contactEmail !== null) {
            $contact['email'] = $this->config->contactEmail;
        }

        if ($this->config->contactUrl !== null) {
            $contact['url'] = $this->config->contactUrl;
        }

        return $contact;
    }

    /**
     * @return array<string, string>
     */
    private function buildLicense(): array
    {
        $license = [];

        if ($this->config->licenseName !== null) {
            $license['name'] = $this->config->licenseName;
        }

        if ($this->config->licenseUrl !== null) {
            $license['url'] = $this->config->licenseUrl;
        }

        return $license;
    }

    /**
     * Build all path items from endpoint metadata.
     *
     * @param list<EndpointMetadata> $endpoints
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildPaths(array $endpoints): array
    {
        $paths = [];

        foreach ($endpoints as $endpoint) {
            $openApiPath = $this->convertPath($endpoint->path);

            if (!array_key_exists($openApiPath, $paths)) {
                $paths[$openApiPath] = [];
            }

            foreach ($endpoint->methods as $method) {
                $httpMethod = strtolower($method);
                $paths[$openApiPath][$httpMethod] = $this->buildOperation($endpoint, $method);
            }
        }

        return $paths;
    }

    /**
     * Convert a framework route path to OpenAPI path format.
     *
     * Framework uses `{param}` which is the same as OpenAPI, so this
     * is mostly pass-through with normalization.
     */
    private function convertPath(string $path): string
    {
        // Remove optional parameter markers (framework-specific)
        return str_replace('?}', '}', $path);
    }

    /**
     * Build a single operation object.
     *
     * @return array<string, mixed>
     */
    private function buildOperation(EndpointMetadata $endpoint, string $method): array
    {
        $operation = [];

        if ($endpoint->doc !== null) {
            if ($endpoint->doc->operationId !== null) {
                $operation['operationId'] = $endpoint->doc->operationId;
            }

            if ($endpoint->doc->summary !== '') {
                $operation['summary'] = $endpoint->doc->summary;
            }

            if ($endpoint->doc->description !== '') {
                $operation['description'] = $endpoint->doc->description;
            }

            if ($endpoint->doc->tags !== []) {
                $operation['tags'] = $endpoint->doc->tags;
            }

            if ($endpoint->doc->deprecated) {
                $operation['deprecated'] = true;
            }
        }

        $parameters = $this->buildParameters($endpoint);
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        // Add request body for methods that support it
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            $requestBody = $this->buildRequestBody($endpoint);
            if ($requestBody !== null) {
                $operation['requestBody'] = $requestBody;
            }
        }

        $responses = $this->buildResponses($endpoint);
        $operation['responses'] = $responses;

        if ($endpoint->securitySchemes !== []) {
            $operation['security'] = array_map(
                static fn(string $scheme): array => [$scheme => []],
                $endpoint->securitySchemes,
            );
        }

        return $operation;
    }

    /**
     * Build parameter objects from endpoint metadata.
     *
     * Combines explicitly declared `#[ApiParam]` parameters with path
     * parameters inferred from the route pattern.
     *
     * @return list<array<string, mixed>>
     */
    private function buildParameters(EndpointMetadata $endpoint): array
    {
        $parameters = [];
        $declaredNames = [];

        // Explicit parameters from attributes
        foreach ($endpoint->params as $param) {
            $parameters[] = $this->paramToOpenApi($param);
            $declaredNames[] = $param->name;
        }

        // Infer missing path parameters from the route pattern
        preg_match_all('/\{(\w+)\??}/', $endpoint->path, $matches);
        foreach ($matches[1] as $paramName) {
            if (!in_array($paramName, $declaredNames, true)) {
                $parameters[] = [
                    'name' => $paramName,
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ];
            }
        }

        return $parameters;
    }

    /**
     * Convert an ApiParam attribute to an OpenAPI parameter object.
     *
     * @return array<string, mixed>
     */
    private function paramToOpenApi(ApiParam $param): array
    {
        $schema = ['type' => $param->type];

        if ($param->format !== null) {
            $schema['format'] = $param->format;
        }

        if ($param->enum !== null) {
            $schema['enum'] = $param->enum;
        }

        if ($param->default !== null) {
            $schema['default'] = $param->default;
        }

        $parameter = [
            'name' => $param->name,
            'in' => $param->in,
            'schema' => $schema,
        ];

        if ($param->required || $param->in === 'path') {
            $parameter['required'] = true;
        }

        if ($param->description !== '') {
            $parameter['description'] = $param->description;
        }

        if ($param->example !== null) {
            $parameter['example'] = $param->example;
        }

        return $parameter;
    }

    /**
     * Build a request body object from response attributes that reference a schema
     * for write operations.
     *
     * @return array<string, mixed>|null
     */
    private function buildRequestBody(EndpointMetadata $endpoint): ?array
    {
        // Look for a response with status 200/201 that has a schema as the request body hint
        foreach ($endpoint->responses as $response) {
            if ($response->schema !== null && in_array($response->status, [200, 201], true)) {
                $this->registerSchema($response->schema);
                $shortName = $this->schemaInferrer->shortName($response->schema);

                return [
                    'required' => true,
                    'content' => [
                        $response->mediaType => [
                            'schema' => ['$ref' => '#/components/schemas/' . $shortName],
                        ],
                    ],
                ];
            }
        }

        return null;
    }

    /**
     * Build response objects from endpoint metadata.
     *
     * Keys are HTTP status codes (may be int due to PHP numeric string coercion).
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function buildResponses(EndpointMetadata $endpoint): array
    {
        $responses = [];

        if ($endpoint->responses === []) {
            $description = 'Successful operation';
            $responses['200'] = ['description' => $description];
        } else {
            foreach ($endpoint->responses as $response) {
                $responses[(string) $response->status] = $this->responseToOpenApi($response);
            }
        }

        return $responses;
    }

    /**
     * Convert an ApiResponse attribute to an OpenAPI response object.
     *
     * @return array<string, mixed>
     */
    private function responseToOpenApi(ApiResponse $response): array
    {
        $obj = [
            'description' => $response->description !== '' ? $response->description : 'Response',
        ];

        if ($response->schema !== null) {
            $this->registerSchema($response->schema);
            $shortName = $this->schemaInferrer->shortName($response->schema);

            $schema = $response->isCollection
                ? ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/' . $shortName]]
                : ['$ref' => '#/components/schemas/' . $shortName];

            $obj['content'] = [
                $response->mediaType => ['schema' => $schema],
            ];
        }

        if ($response->headers !== null) {
            $obj['headers'] = array_map(
                static fn(string $description): array => [
                    'description' => $description,
                    'schema' => ['type' => 'string'],
                ],
                $response->headers,
            );
        }

        return $obj;
    }

    /**
     * Register a schema class for inclusion in components/schemas.
     *
     * @param class-string $className
     */
    private function registerSchema(string $className): void
    {
        $shortName = $this->schemaInferrer->shortName($className);

        if (array_key_exists($shortName, $this->schemas)) {
            return;
        }

        $this->schemas[$shortName] = $this->schemaInferrer->infer($className);
    }

    /**
     * Build the components section of the spec.
     *
     * @return array<string, mixed>
     */
    private function buildComponents(): array
    {
        $components = [];

        if ($this->schemas !== []) {
            ksort($this->schemas);
            $components['schemas'] = $this->schemas;
        }

        $securitySchemes = $this->buildSecuritySchemeComponents();
        if ($securitySchemes !== []) {
            $components['securitySchemes'] = $securitySchemes;
        }

        return $components;
    }

    /**
     * Build security scheme component objects from config.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildSecuritySchemeComponents(): array
    {
        $schemes = [];

        foreach ($this->config->securitySchemes as $scheme) {
            $schemes[$scheme->name] = $scheme->toOpenApi();
        }

        return $schemes;
    }

    /**
     * Build the top-level security requirements array.
     *
     * @return list<array<string, list<string>>>
     */
    private function buildGlobalSecurity(): array
    {
        if ($this->config->securitySchemes === []) {
            return [];
        }

        return array_map(
            static fn(SecuritySchemeDefinition $scheme): array => [$scheme->name => []],
            $this->config->securitySchemes,
        );
    }

    /**
     * Collect and deduplicate tags from all endpoints.
     *
     * @param list<EndpointMetadata> $endpoints
     *
     * @return list<array{name: string}>
     */
    private function collectTags(array $endpoints): array
    {
        $tags = [];

        foreach ($endpoints as $endpoint) {
            if ($endpoint->doc === null) {
                continue;
            }

            foreach ($endpoint->doc->tags as $tag) {
                $tags[$tag] = true;
            }
        }

        ksort($tags);

        return array_map(
            static fn(string $tag): array => ['name' => $tag],
            array_keys($tags),
        );
    }
}
