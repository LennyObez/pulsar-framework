<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Format;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Format\JsonApiRenderer;
use Pulsar\Api\Format\ResponseContext;
use Pulsar\Api\Pagination\PaginationLinks;
use Pulsar\Api\Pagination\PaginationMeta;

#[CoversClass(JsonApiRenderer::class)]
final class JsonApiRendererTest extends TestCase
{
    private JsonApiRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new JsonApiRenderer();
    }

    #[Test]
    public function rendersSingleResourceInJsonApiFormat(): void
    {
        $data = ['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com'];
        $context = new ResponseContext(resourceType: 'users');

        $output = $this->renderer->render($data, $context);

        self::assertArrayHasKey('data', $output);
        self::assertIsArray($output['data']);
        self::assertSame('users', $output['data']['type']);
        self::assertSame('1', $output['data']['id']);
        self::assertArrayHasKey('attributes', $output['data']);
        self::assertIsArray($output['data']['attributes']);
        self::assertSame('Alice', $output['data']['attributes']['name']);
        self::assertSame('alice@example.com', $output['data']['attributes']['email']);
    }

    #[Test]
    public function reservedFieldsExcludedFromAttributes(): void
    {
        $data = ['id' => '1', 'type' => 'users', 'name' => 'Alice'];
        $context = new ResponseContext(resourceType: 'users');

        $output = $this->renderer->render($data, $context);

        self::assertIsArray($output['data']);
        self::assertIsArray($output['data']['attributes']);
        self::assertArrayNotHasKey('id', $output['data']['attributes']);
        self::assertArrayNotHasKey('type', $output['data']['attributes']);
        self::assertSame('Alice', $output['data']['attributes']['name']);
    }

    #[Test]
    public function usesContextResourceTypeWhenNotInData(): void
    {
        $data = ['id' => '5', 'name' => 'Alice'];
        $context = new ResponseContext(resourceType: 'users');

        $output = $this->renderer->render($data, $context);

        self::assertIsArray($output['data']);
        self::assertSame('users', $output['data']['type']);
    }

    #[Test]
    public function usesDataTypeFieldWhenPresent(): void
    {
        $data = ['id' => '5', 'type' => 'admins', 'name' => 'Alice'];
        $context = new ResponseContext(resourceType: 'users');

        $output = $this->renderer->render($data, $context);

        self::assertIsArray($output['data']);
        self::assertSame('admins', $output['data']['type']);
    }

    #[Test]
    public function castsNumericIdToString(): void
    {
        $data = ['id' => 42, 'name' => 'Alice'];
        $context = new ResponseContext(resourceType: 'users');

        $output = $this->renderer->render($data, $context);

        self::assertIsArray($output['data']);
        self::assertSame('42', $output['data']['id']);
    }

    #[Test]
    public function includesMetaOnSingleResource(): void
    {
        $data = ['id' => '1', 'name' => 'Alice'];
        $context = new ResponseContext(resourceType: 'users', meta: ['version' => '1.0']);

        $output = $this->renderer->render($data, $context);

        self::assertSame(['version' => '1.0'], $output['meta']);
    }

    #[Test]
    public function singleResourceOmitsEmptyMeta(): void
    {
        $data = ['id' => '1', 'name' => 'Alice'];
        $context = new ResponseContext(resourceType: 'users');

        $output = $this->renderer->render($data, $context);

        self::assertArrayNotHasKey('meta', $output);
    }

    #[Test]
    public function extractsMetaFromAttributes(): void
    {
        $data = ['id' => '1', 'name' => 'Alice', '_meta' => ['redactions' => ['ssn' => 'denied']]];
        $context = new ResponseContext(resourceType: 'users');

        $output = $this->renderer->render($data, $context);

        self::assertIsArray($output['data']);
        self::assertIsArray($output['data']['attributes']);
        self::assertArrayNotHasKey('_meta', $output['data']['attributes']);
        self::assertArrayHasKey('meta', $output['data']);
        self::assertSame(['redactions' => ['ssn' => 'denied']], $output['data']['meta']);
    }

    #[Test]
    public function rendersCollection(): void
    {
        $items = [
            ['id' => '1', 'name' => 'Alice'],
            ['id' => '2', 'name' => 'Bob'],
        ];
        $context = new ResponseContext(resourceType: 'users');

        $output = $this->renderer->renderCollection($items, $context);

        self::assertIsArray($output['data']);
        self::assertCount(2, $output['data']);
        self::assertIsArray($output['data'][0]);
        self::assertSame('users', $output['data'][0]['type']);
        self::assertSame('1', $output['data'][0]['id']);
        self::assertIsArray($output['data'][0]['attributes']);
        self::assertSame('Alice', $output['data'][0]['attributes']['name']);
        self::assertIsArray($output['data'][1]);
        self::assertSame('users', $output['data'][1]['type']);
        self::assertSame('2', $output['data'][1]['id']);
    }

    #[Test]
    public function collectionIncludesPaginationMeta(): void
    {
        $items = [['id' => '1', 'name' => 'Alice']];
        $paginationMeta = new PaginationMeta(perPage: 10, hasMore: true, total: 42);
        $context = new ResponseContext(
            resourceType: 'users',
            paginationMeta: $paginationMeta,
        );

        $output = $this->renderer->renderCollection($items, $context);

        self::assertArrayHasKey('meta', $output);
        self::assertIsArray($output['meta']);
        self::assertSame(10, $output['meta']['per_page']);
        self::assertSame(42, $output['meta']['total']);
    }

    #[Test]
    public function collectionIncludesLinks(): void
    {
        $items = [['id' => '1', 'name' => 'Alice']];
        $links = new PaginationLinks(first: '/api/users?page=1', next: '/api/users?page=2');
        $context = new ResponseContext(
            resourceType: 'users',
            paginationLinks: $links,
        );

        $output = $this->renderer->renderCollection($items, $context);

        self::assertArrayHasKey('links', $output);
        self::assertIsArray($output['links']);
        self::assertSame('/api/users?page=1', $output['links']['first']);
        self::assertSame('/api/users?page=2', $output['links']['next']);
    }

    #[Test]
    public function contentType(): void
    {
        self::assertSame('application/vnd.api+json', $this->renderer->contentType());
    }
}
