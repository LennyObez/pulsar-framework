<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\OpenApi\Attribute\ApiDoc;
use Pulsar\Api\OpenApi\Attribute\ApiParam;
use Pulsar\Api\OpenApi\Attribute\ApiResponse;
use Pulsar\Api\OpenApi\EndpointMetadata;
use Pulsar\Api\OpenApi\OpenApiConfig;
use Pulsar\Api\OpenApi\SchemaInferrer;
use Pulsar\Api\OpenApi\SecuritySchemeDefinition;
use Pulsar\Api\OpenApi\SpecGenerator;

#[CoversClass(SpecGenerator::class)]
final class SpecGeneratorTest extends TestCase
{
    private SpecGenerator $generator;

    protected function setUp(): void
    {
        $config = new OpenApiConfig(
            title: 'Test API',
            version: '2.0.0',
            description: 'Test API description',
        );

        $this->generator = new SpecGenerator($config, new SchemaInferrer());
    }

    #[Test]
    public function generatesValidOpenApiVersion(): void
    {
        $spec = $this->generator->generate([]);

        self::assertSame('3.1.0', $spec['openapi']);
    }

    #[Test]
    public function infoSectionContainsTitleAndVersion(): void
    {
        $spec = $this->generator->generate([]);

        self::assertIsArray($spec['info']);
        self::assertSame('Test API', $spec['info']['title']);
        self::assertSame('2.0.0', $spec['info']['version']);
        self::assertSame('Test API description', $spec['info']['description']);
    }

    #[Test]
    public function emptyEndpointsProduceEmptyPaths(): void
    {
        $spec = $this->generator->generate([]);

        self::assertSame([], $spec['paths']);
    }

    #[Test]
    public function singleEndpointProducesPathItem(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/users',
            methods: ['GET'],
            doc: new ApiDoc(summary: 'List users', tags: ['Users']),
        );

        $spec = $this->generator->generate([$endpoint]);

        self::assertIsArray($spec['paths']);
        self::assertArrayHasKey('/api/users', $spec['paths']);
        self::assertIsArray($spec['paths']['/api/users']);
        self::assertArrayHasKey('get', $spec['paths']['/api/users']);
        self::assertIsArray($spec['paths']['/api/users']['get']);
        self::assertSame('List users', $spec['paths']['/api/users']['get']['summary']);
    }

    #[Test]
    public function pathParametersInferredFromPath(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/users/{id}',
            methods: ['GET'],
        );

        $spec = $this->generator->generate([$endpoint]);

        self::assertIsArray($spec['paths']);
        self::assertIsArray($spec['paths']['/api/users/{id}']);
        self::assertIsArray($spec['paths']['/api/users/{id}']['get']);
        $params = $spec['paths']['/api/users/{id}']['get']['parameters'];
        self::assertIsArray($params);
        self::assertCount(1, $params);
        self::assertIsArray($params[0]);
        self::assertSame('id', $params[0]['name']);
        self::assertSame('path', $params[0]['in']);
        self::assertTrue($params[0]['required']);
    }

    #[Test]
    public function explicitParametersOverrideInferred(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/users/{id}',
            methods: ['GET'],
            params: [
                new ApiParam(
                    name: 'id',
                    in: 'path',
                    type: 'integer',
                    required: true,
                    description: 'User ID',
                    format: 'int64',
                ),
            ],
        );

        $spec = $this->generator->generate([$endpoint]);

        self::assertIsArray($spec['paths']);
        self::assertIsArray($spec['paths']['/api/users/{id}']);
        self::assertIsArray($spec['paths']['/api/users/{id}']['get']);
        $params = $spec['paths']['/api/users/{id}']['get']['parameters'];
        self::assertIsArray($params);
        self::assertCount(1, $params); // Should not duplicate
        self::assertIsArray($params[0]);
        self::assertIsArray($params[0]['schema']);
        self::assertSame('integer', $params[0]['schema']['type']);
        self::assertSame('int64', $params[0]['schema']['format']);
        self::assertSame('User ID', $params[0]['description']);
    }

    #[Test]
    public function responsesIncludedInOperation(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/users',
            methods: ['GET'],
            responses: [
                new ApiResponse(status: 200, description: 'User list'),
                new ApiResponse(status: 404, description: 'Not found'),
            ],
        );

        $spec = $this->generator->generate([$endpoint]);

        self::assertIsArray($spec['paths']);
        self::assertIsArray($spec['paths']['/api/users']);
        self::assertIsArray($spec['paths']['/api/users']['get']);
        $responses = $spec['paths']['/api/users']['get']['responses'];
        self::assertIsArray($responses);
        self::assertArrayHasKey('200', $responses);
        self::assertArrayHasKey('404', $responses);
        self::assertIsArray($responses['200']);
        self::assertSame('User list', $responses['200']['description']);
    }

    #[Test]
    public function defaultResponseWhenNoneSpecified(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/health',
            methods: ['GET'],
        );

        $spec = $this->generator->generate([$endpoint]);

        self::assertIsArray($spec['paths']);
        self::assertIsArray($spec['paths']['/api/health']);
        self::assertIsArray($spec['paths']['/api/health']['get']);
        self::assertIsArray($spec['paths']['/api/health']['get']['responses']);
        self::assertArrayHasKey('200', $spec['paths']['/api/health']['get']['responses']);
        self::assertIsArray($spec['paths']['/api/health']['get']['responses']['200']);
        self::assertSame('Successful operation', $spec['paths']['/api/health']['get']['responses']['200']['description']);
    }

    #[Test]
    public function multipleMethodsOnSamePath(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/users',
            methods: ['GET', 'POST'],
            doc: new ApiDoc(summary: 'Users endpoint'),
        );

        $spec = $this->generator->generate([$endpoint]);

        self::assertIsArray($spec['paths']);
        self::assertIsArray($spec['paths']['/api/users']);
        self::assertArrayHasKey('get', $spec['paths']['/api/users']);
        self::assertArrayHasKey('post', $spec['paths']['/api/users']);
    }

    #[Test]
    public function deprecatedEndpointFlagged(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/legacy',
            methods: ['GET'],
            doc: new ApiDoc(summary: 'Legacy', deprecated: true),
        );

        $spec = $this->generator->generate([$endpoint]);

        self::assertIsArray($spec['paths']);
        self::assertIsArray($spec['paths']['/api/legacy']);
        self::assertIsArray($spec['paths']['/api/legacy']['get']);
        self::assertTrue($spec['paths']['/api/legacy']['get']['deprecated']);
    }

    #[Test]
    public function operationIdIncluded(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/users',
            methods: ['GET'],
            doc: new ApiDoc(operationId: 'listUsers'),
        );

        $spec = $this->generator->generate([$endpoint]);

        self::assertIsArray($spec['paths']);
        self::assertIsArray($spec['paths']['/api/users']);
        self::assertIsArray($spec['paths']['/api/users']['get']);
        self::assertSame('listUsers', $spec['paths']['/api/users']['get']['operationId']);
    }

    #[Test]
    public function tagsCollectedAndDeduplicated(): void
    {
        $endpoints = [
            new EndpointMetadata(
                path: '/api/users',
                methods: ['GET'],
                doc: new ApiDoc(tags: ['Users', 'Admin']),
            ),
            new EndpointMetadata(
                path: '/api/posts',
                methods: ['GET'],
                doc: new ApiDoc(tags: ['Users', 'Posts']),
            ),
        ];

        $spec = $this->generator->generate($endpoints);

        self::assertIsArray($spec['tags']);
        $tagNames = array_column($spec['tags'], 'name');
        self::assertSame(['Admin', 'Posts', 'Users'], $tagNames); // Sorted, deduplicated
    }

    #[Test]
    public function pathsSortedAlphabetically(): void
    {
        $endpoints = [
            new EndpointMetadata(path: '/api/zebra', methods: ['GET']),
            new EndpointMetadata(path: '/api/apple', methods: ['GET']),
            new EndpointMetadata(path: '/api/mango', methods: ['GET']),
        ];

        $spec = $this->generator->generate($endpoints);

        self::assertIsArray($spec['paths']);
        $paths = array_keys($spec['paths']);
        self::assertSame(['/api/apple', '/api/mango', '/api/zebra'], $paths);
    }

    #[Test]
    public function securitySchemesIncludedInComponents(): void
    {
        $config = new OpenApiConfig(
            title: 'Secure API',
            version: '1.0.0',
            securitySchemes: [
                SecuritySchemeDefinition::bearer(),
            ],
        );

        $generator = new SpecGenerator($config, new SchemaInferrer());
        $spec = $generator->generate([]);

        self::assertArrayHasKey('components', $spec);
        self::assertIsArray($spec['components']);
        self::assertArrayHasKey('securitySchemes', $spec['components']);
        self::assertIsArray($spec['components']['securitySchemes']);
        self::assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
        self::assertIsArray($spec['components']['securitySchemes']['bearerAuth']);
        self::assertSame('http', $spec['components']['securitySchemes']['bearerAuth']['type']);
        self::assertSame('bearer', $spec['components']['securitySchemes']['bearerAuth']['scheme']);
    }

    #[Test]
    public function globalSecurityRequirement(): void
    {
        $config = new OpenApiConfig(
            title: 'Secure API',
            version: '1.0.0',
            securitySchemes: [
                SecuritySchemeDefinition::bearer(),
            ],
        );

        $generator = new SpecGenerator($config, new SchemaInferrer());
        $spec = $generator->generate([]);

        self::assertArrayHasKey('security', $spec);
        self::assertIsArray($spec['security']);
        self::assertCount(1, $spec['security']);
        self::assertSame(['bearerAuth' => []], $spec['security'][0]);
    }

    #[Test]
    public function responseSchemaGeneratesComponentRef(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/users',
            methods: ['GET'],
            responses: [
                new ApiResponse(
                    status: 200,
                    description: 'User list',
                    schema: SpecGeneratorTestDto::class,
                    isCollection: true,
                ),
            ],
        );

        $spec = $this->generator->generate([$endpoint]);

        self::assertIsArray($spec['paths']);
        self::assertIsArray($spec['paths']['/api/users']);
        self::assertIsArray($spec['paths']['/api/users']['get']);
        self::assertIsArray($spec['paths']['/api/users']['get']['responses']);
        $response = $spec['paths']['/api/users']['get']['responses']['200'];
        self::assertIsArray($response);
        self::assertArrayHasKey('content', $response);

        self::assertIsArray($response['content']);
        self::assertIsArray($response['content']['application/json']);
        $schema = $response['content']['application/json']['schema'];
        self::assertIsArray($schema);
        self::assertSame('array', $schema['type']);
        self::assertIsArray($schema['items']);
        self::assertSame('#/components/schemas/SpecGeneratorTestDto', $schema['items']['$ref']);

        // Schema should be registered in components
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        self::assertArrayHasKey('SpecGeneratorTestDto', $spec['components']['schemas']);
    }

    #[Test]
    public function singleResourceResponseUsesDirectRef(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/users/{id}',
            methods: ['GET'],
            responses: [
                new ApiResponse(
                    status: 200,
                    description: 'Single user',
                    schema: SpecGeneratorTestDto::class,
                ),
            ],
        );

        $spec = $this->generator->generate([$endpoint]);

        self::assertIsArray($spec['paths']);
        self::assertIsArray($spec['paths']['/api/users/{id}']);
        self::assertIsArray($spec['paths']['/api/users/{id}']['get']);
        self::assertIsArray($spec['paths']['/api/users/{id}']['get']['responses']);
        self::assertIsArray($spec['paths']['/api/users/{id}']['get']['responses']['200']);
        self::assertIsArray($spec['paths']['/api/users/{id}']['get']['responses']['200']['content']);
        self::assertIsArray($spec['paths']['/api/users/{id}']['get']['responses']['200']['content']['application/json']);
        $schema = $spec['paths']['/api/users/{id}']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertIsArray($schema);
        self::assertSame('#/components/schemas/SpecGeneratorTestDto', $schema['$ref']);
    }

    #[Test]
    public function serversIncludedWhenConfigured(): void
    {
        $config = new OpenApiConfig(
            title: 'API',
            version: '1.0.0',
            servers: [
                ['url' => 'https://api.example.com', 'description' => 'Production'],
            ],
        );

        $generator = new SpecGenerator($config, new SchemaInferrer());
        $spec = $generator->generate([]);

        self::assertIsArray($spec['servers']);
        self::assertCount(1, $spec['servers']);
        self::assertIsArray($spec['servers'][0]);
        self::assertSame('https://api.example.com', $spec['servers'][0]['url']);
    }

    #[Test]
    public function serversOmittedWhenEmpty(): void
    {
        $spec = $this->generator->generate([]);

        self::assertArrayNotHasKey('servers', $spec);
    }

    #[Test]
    public function generateJsonProducesValidJson(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/test',
            methods: ['GET'],
            doc: new ApiDoc(summary: 'Test endpoint'),
        );

        $json = $this->generator->generateJson([$endpoint]);

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('3.1.0', $decoded['openapi']);
        self::assertIsArray($decoded['paths']);
        self::assertArrayHasKey('/api/test', $decoded['paths']);
    }

    #[Test]
    public function contactInfoIncludedWhenConfigured(): void
    {
        $config = new OpenApiConfig(
            title: 'API',
            version: '1.0.0',
            contactName: 'Support Team',
            contactEmail: 'support@example.com',
            contactUrl: 'https://support.example.com',
        );

        $generator = new SpecGenerator($config, new SchemaInferrer());
        $spec = $generator->generate([]);

        self::assertIsArray($spec['info']);
        self::assertIsArray($spec['info']['contact']);
        self::assertSame('Support Team', $spec['info']['contact']['name']);
        self::assertSame('support@example.com', $spec['info']['contact']['email']);
    }

    #[Test]
    public function licenseInfoIncludedWhenConfigured(): void
    {
        $config = new OpenApiConfig(
            title: 'API',
            version: '1.0.0',
            licenseName: 'MIT',
            licenseUrl: 'https://opensource.org/licenses/MIT',
        );

        $generator = new SpecGenerator($config, new SchemaInferrer());
        $spec = $generator->generate([]);

        self::assertIsArray($spec['info']);
        self::assertIsArray($spec['info']['license']);
        self::assertSame('MIT', $spec['info']['license']['name']);
        self::assertSame('https://opensource.org/licenses/MIT', $spec['info']['license']['url']);
    }

    #[Test]
    public function optionalPathParametersHandled(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/users/{id?}',
            methods: ['GET'],
        );

        $spec = $this->generator->generate([$endpoint]);

        // Optional ? should be stripped from OpenAPI path
        self::assertIsArray($spec['paths']);
        self::assertArrayHasKey('/api/users/{id}', $spec['paths']);
    }

    #[Test]
    public function endpointSecuritySchemesIncluded(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/admin',
            methods: ['GET'],
            securitySchemes: ['bearerAuth'],
        );

        $spec = $this->generator->generate([$endpoint]);

        self::assertIsArray($spec['paths']);
        self::assertIsArray($spec['paths']['/api/admin']);
        self::assertIsArray($spec['paths']['/api/admin']['get']);
        $security = $spec['paths']['/api/admin']['get']['security'];
        self::assertIsArray($security);
        self::assertCount(1, $security);
        self::assertSame(['bearerAuth' => []], $security[0]);
    }

    #[Test]
    public function queryParameterIncluded(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/users',
            methods: ['GET'],
            params: [
                new ApiParam(
                    name: 'page',
                    in: 'query',
                    type: 'integer',
                    description: 'Page number',
                    default: 1,
                ),
            ],
        );

        $spec = $this->generator->generate([$endpoint]);

        self::assertIsArray($spec['paths']);
        self::assertIsArray($spec['paths']['/api/users']);
        self::assertIsArray($spec['paths']['/api/users']['get']);
        self::assertIsArray($spec['paths']['/api/users']['get']['parameters']);
        $param = $spec['paths']['/api/users']['get']['parameters'][0];
        self::assertIsArray($param);
        self::assertSame('page', $param['name']);
        self::assertSame('query', $param['in']);
        self::assertIsArray($param['schema']);
        self::assertSame(1, $param['schema']['default']);
    }

    #[Test]
    public function responseHeadersIncluded(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/users',
            methods: ['GET'],
            responses: [
                new ApiResponse(
                    status: 200,
                    description: 'OK',
                    headers: ['X-Total-Count' => 'Total number of items'],
                ),
            ],
        );

        $spec = $this->generator->generate([$endpoint]);

        self::assertIsArray($spec['paths']);
        self::assertIsArray($spec['paths']['/api/users']);
        self::assertIsArray($spec['paths']['/api/users']['get']);
        self::assertIsArray($spec['paths']['/api/users']['get']['responses']);
        self::assertIsArray($spec['paths']['/api/users']['get']['responses']['200']);
        $headers = $spec['paths']['/api/users']['get']['responses']['200']['headers'];
        self::assertIsArray($headers);
        self::assertArrayHasKey('X-Total-Count', $headers);
        self::assertIsArray($headers['X-Total-Count']);
        self::assertSame('Total number of items', $headers['X-Total-Count']['description']);
    }

    #[Test]
    public function requestBodyGeneratedForPostWithSchema(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/users',
            methods: ['POST'],
            responses: [
                new ApiResponse(
                    status: 201,
                    description: 'Created',
                    schema: SpecGeneratorTestDto::class,
                ),
            ],
        );

        $spec = $this->generator->generate([$endpoint]);

        self::assertIsArray($spec['paths']);
        self::assertIsArray($spec['paths']['/api/users']);
        $operation = $spec['paths']['/api/users']['post'];
        self::assertIsArray($operation);
        self::assertArrayHasKey('requestBody', $operation);
        self::assertIsArray($operation['requestBody']);
        self::assertTrue($operation['requestBody']['required']);
    }

    #[Test]
    public function enumParamIncludesAllowedValues(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/users',
            methods: ['GET'],
            params: [
                new ApiParam(
                    name: 'status',
                    in: 'query',
                    type: 'string',
                    enum: ['active', 'inactive', 'suspended'],
                ),
            ],
        );

        $spec = $this->generator->generate([$endpoint]);

        self::assertIsArray($spec['paths']);
        self::assertIsArray($spec['paths']['/api/users']);
        self::assertIsArray($spec['paths']['/api/users']['get']);
        self::assertIsArray($spec['paths']['/api/users']['get']['parameters']);
        $param = $spec['paths']['/api/users']['get']['parameters'][0];
        self::assertIsArray($param);
        self::assertIsArray($param['schema']);
        self::assertSame(['active', 'inactive', 'suspended'], $param['schema']['enum']);
    }

    #[Test]
    public function paramExampleIncluded(): void
    {
        $endpoint = new EndpointMetadata(
            path: '/api/users',
            methods: ['GET'],
            params: [
                new ApiParam(
                    name: 'name',
                    in: 'query',
                    example: 'John',
                ),
            ],
        );

        $spec = $this->generator->generate([$endpoint]);

        self::assertIsArray($spec['paths']);
        self::assertIsArray($spec['paths']['/api/users']);
        self::assertIsArray($spec['paths']['/api/users']['get']);
        self::assertIsArray($spec['paths']['/api/users']['get']['parameters']);
        self::assertIsArray($spec['paths']['/api/users']['get']['parameters'][0]);
        self::assertSame('John', $spec['paths']['/api/users']['get']['parameters'][0]['example']);
    }
}

/**
 * Test DTO for schema inference.
 */
final class SpecGeneratorTestDto
{
    public string $id = '';
    public string $name = '';
    public ?int $age = null;
}
