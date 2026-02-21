<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Format\HalRenderer;
use Pulsar\Api\Format\JsonApiRenderer;
use Pulsar\Api\Format\JsonRenderer;
use Pulsar\Api\Format\ResponseContext;
use Pulsar\Api\Pagination\PaginationLinks;
use Pulsar\Api\Pagination\PaginationMeta;
use Pulsar\Api\Resource\AbstractApiResource;
use Pulsar\Api\Resource\Attribute\ApiResource;
use Pulsar\Api\Resource\Attribute\ClassificationTag;
use Pulsar\Api\Resource\Attribute\Expose;
use Pulsar\Api\Resource\RedactionRule;
use Pulsar\Api\Resource\RedactionStrategy;
use Pulsar\Api\Security\ClearanceSnapshot;
use Pulsar\Security\Compliance\DataClassification;

/**
 * Integration test: full resource→serialize→render pipeline.
 *
 * Exercises the complete path from AbstractApiResource through content
 * negotiation to response rendering in all three formats.
 */
#[CoversClass(AbstractApiResource::class)]
#[CoversClass(JsonRenderer::class)]
#[CoversClass(JsonApiRenderer::class)]
#[CoversClass(HalRenderer::class)]
final class ApiPipelineIntegrationTest extends TestCase
{
    #[Test]
    public function fullPipelineWithJsonRenderer(): void
    {
        $resource = $this->buildResource();

        $data = $resource->toArray();
        $renderer = new JsonRenderer();
        $context = new ResponseContext(resourceType: 'pipeline_users');

        $output = $renderer->render($data, $context);

        self::assertArrayHasKey('data', $output);
        self::assertIsArray($output['data']);
        self::assertSame('u-1', $output['data']['id']);
        self::assertSame('Alice', $output['data']['name']);
        self::assertArrayNotHasKey('passwordHash', $output['data']);
    }

    #[Test]
    public function fullPipelineWithJsonApiRenderer(): void
    {
        $resource = $this->buildResource();

        $data = $resource->toArray();
        $renderer = new JsonApiRenderer();
        $context = new ResponseContext(resourceType: 'pipeline_users');

        $output = $renderer->render($data, $context);

        self::assertArrayHasKey('data', $output);
        self::assertIsArray($output['data']);
        self::assertSame('pipeline_users', $output['data']['type']);
        self::assertSame('u-1', $output['data']['id']);
        self::assertArrayHasKey('attributes', $output['data']);
        self::assertIsArray($output['data']['attributes']);
        self::assertSame('Alice', $output['data']['attributes']['name']);
    }

    #[Test]
    public function fullPipelineWithHalRenderer(): void
    {
        $resource = $this->buildResource();

        $data = $resource->toArray();
        $renderer = new HalRenderer();
        $context = new ResponseContext(resourceType: 'pipeline_users');

        $output = $renderer->render($data, $context);

        self::assertArrayHasKey('_links', $output);
        self::assertSame('u-1', $output['id']);
        self::assertSame('Alice', $output['name']);
    }

    #[Test]
    public function collectionPipelineWithPaginationMeta(): void
    {
        $resources = [];
        for ($i = 1; $i <= 3; $i++) {
            $r = new PipelineTestResource();
            $r->id = "u-{$i}";
            $r->name = "User {$i}";
            $r->email = "user{$i}@example.com";
            $resources[] = $r->toArray();
        }

        $renderer = new JsonRenderer();
        $context = new ResponseContext(
            resourceType: 'pipeline_users',
            paginationMeta: new PaginationMeta(
                perPage: 10,
                hasMore: false,
                total: 3,
                currentPage: 1,
                lastPage: 1,
            ),
            paginationLinks: new PaginationLinks(
                first: '/api/users?page=1',
                last: '/api/users?page=1',
            ),
        );

        $output = $renderer->renderCollection($resources, $context);

        self::assertIsArray($output['data']);
        self::assertCount(3, $output['data']);
        self::assertArrayHasKey('meta', $output);
        self::assertIsArray($output['meta']);
        self::assertSame(3, $output['meta']['total']);
        self::assertArrayHasKey('links', $output);
        self::assertIsArray($output['links']);
        self::assertSame('/api/users?page=1', $output['links']['first']);
    }

    #[Test]
    public function clearanceFilteringInPipeline(): void
    {
        $resource = new PipelineTestResource();
        $resource->id = 'u-10';
        $resource->name = 'Bob';
        $resource->email = 'bob@example.com';
        $resource->passwordHash = 'hashed';

        // Public-only clearance — Confidential email should be excluded
        $clearance = ClearanceSnapshot::anonymous('snap-pipe');
        $data = $resource->toArray($clearance);

        $renderer = new JsonRenderer();
        $output = $renderer->render($data, new ResponseContext(resourceType: 'pipeline_users'));

        self::assertIsArray($output['data']);
        self::assertArrayHasKey('id', $output['data']);
        self::assertArrayHasKey('name', $output['data']);
        self::assertArrayNotHasKey('email', $output['data'], 'Confidential email must not reach renderer');
        self::assertArrayNotHasKey('passwordHash', $output['data'], 'Unexposed field must never reach renderer');
    }

    #[Test]
    public function redactionInPipeline(): void
    {
        $resource = new PipelineTestResource();
        $resource->id = 'u-11';
        $resource->name = 'Carol';
        $resource->email = 'carol@example.com';

        $clearance = new ClearanceSnapshot(
            snapshotId: 'snap-redact-pipe',
            maxClassification: DataClassification::Internal,
            permissions: [],
            roles: [],
            authenticated: true,
        );

        $redactionRules = [
            'email' => new RedactionRule(
                appliesAbove: DataClassification::Internal,
                strategy: RedactionStrategy::Truncate,
                truncateLength: 3,
            ),
        ];

        $data = $resource->toArray($clearance, redactionRules: $redactionRules);

        $renderer = new JsonRenderer();
        $output = $renderer->render($data, new ResponseContext(resourceType: 'pipeline_users'));

        self::assertIsArray($output['data']);
        self::assertSame('car...', $output['data']['email']);
    }

    #[Test]
    public function sparseFieldsetInPipeline(): void
    {
        $resource = new PipelineTestResource();
        $resource->id = 'u-12';
        $resource->name = 'Dave';
        $resource->email = 'dave@example.com';

        $data = $resource->toArray(requestedFields: ['id', 'name']);

        $renderer = new JsonRenderer();
        $output = $renderer->render($data, new ResponseContext(resourceType: 'pipeline_users'));

        self::assertIsArray($output['data']);
        self::assertSame('u-12', $output['data']['id']);
        self::assertSame('Dave', $output['data']['name']);
        self::assertArrayNotHasKey('email', $output['data']);
    }

    #[Test]
    public function collectionWithJsonApiIncludesTypeAndId(): void
    {
        $items = [];
        for ($i = 1; $i <= 2; $i++) {
            $r = new PipelineTestResource();
            $r->id = "u-{$i}";
            $r->name = "User {$i}";
            $items[] = $r->toArray();
        }

        $renderer = new JsonApiRenderer();
        $context = new ResponseContext(resourceType: 'pipeline_users');
        $output = $renderer->renderCollection($items, $context);

        self::assertIsArray($output['data']);
        self::assertCount(2, $output['data']);
        self::assertIsArray($output['data'][0]);
        self::assertSame('pipeline_users', $output['data'][0]['type']);
        self::assertSame('u-1', $output['data'][0]['id']);
    }

    #[Test]
    public function collectionWithHalEmbeds(): void
    {
        $items = [];
        for ($i = 1; $i <= 2; $i++) {
            $r = new PipelineTestResource();
            $r->id = "u-{$i}";
            $r->name = "User {$i}";
            $items[] = $r->toArray();
        }

        $renderer = new HalRenderer();
        $context = new ResponseContext(resourceType: 'pipeline_users');
        $output = $renderer->renderCollection($items, $context);

        self::assertArrayHasKey('_embedded', $output);
        self::assertIsArray($output['_embedded']);
        self::assertArrayHasKey('pipeline_users', $output['_embedded']);
        self::assertIsArray($output['_embedded']['pipeline_users']);
        self::assertCount(2, $output['_embedded']['pipeline_users']);
    }

    private function buildResource(): PipelineTestResource
    {
        $resource = new PipelineTestResource();
        $resource->id = 'u-1';
        $resource->name = 'Alice';
        $resource->email = 'alice@example.com';
        $resource->passwordHash = 'bcrypt_hash';

        return $resource;
    }
}

#[ApiResource(type: 'pipeline_users')]
final class PipelineTestResource extends AbstractApiResource
{
    #[Expose]
    public string $id = '';

    #[Expose]
    public string $name = '';

    #[Expose]
    #[ClassificationTag(DataClassification::Confidential)]
    public string $email = '';

    // NOT exposed — must never appear
    public string $passwordHash = '';
}
