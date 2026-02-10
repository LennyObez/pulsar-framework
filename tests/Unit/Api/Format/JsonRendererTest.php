<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Format;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Format\JsonRenderer;
use Pulsar\Api\Format\ResponseContext;
use Pulsar\Api\Pagination\PaginationLinks;
use Pulsar\Api\Pagination\PaginationMeta;

#[CoversClass(JsonRenderer::class)]
final class JsonRendererTest extends TestCase
{
    private JsonRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new JsonRenderer();
    }

    #[Test]
    public function rendersSingleResource(): void
    {
        $data = ['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com'];
        $context = new ResponseContext(resourceType: 'users');

        $output = $this->renderer->render($data, $context);

        self::assertSame(['data' => $data], $output);
    }

    #[Test]
    public function rendersSingleResourceWithMeta(): void
    {
        $data = ['id' => '1', 'name' => 'Alice'];
        $context = new ResponseContext(resourceType: 'users', meta: ['version' => '1.0']);

        $output = $this->renderer->render($data, $context);

        self::assertSame([
            'data' => $data,
            'meta' => ['version' => '1.0'],
        ], $output);
    }

    #[Test]
    public function singleResourceOmitsEmptyMeta(): void
    {
        $data = ['id' => '1'];
        $context = new ResponseContext();

        $output = $this->renderer->render($data, $context);

        self::assertArrayNotHasKey('meta', $output);
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

        self::assertSame(['data' => $items], $output);
    }

    #[Test]
    public function rendersCollectionWithPaginationMeta(): void
    {
        $items = [['id' => '1']];
        $paginationMeta = new PaginationMeta(
            perPage: 10,
            hasMore: true,
            total: 42,
            currentPage: 1,
            lastPage: 5,
        );
        $context = new ResponseContext(
            resourceType: 'users',
            paginationMeta: $paginationMeta,
        );

        $output = $this->renderer->renderCollection($items, $context);

        self::assertArrayHasKey('meta', $output);
        self::assertIsArray($output['meta']);
        self::assertSame(10, $output['meta']['per_page']);
        self::assertTrue($output['meta']['has_more']);
        self::assertSame(42, $output['meta']['total']);
        self::assertSame(1, $output['meta']['current_page']);
        self::assertSame(5, $output['meta']['last_page']);
    }

    #[Test]
    public function rendersCollectionWithLinks(): void
    {
        $items = [['id' => '1']];
        $links = new PaginationLinks(
            first: '/api/users?page=1',
            last: '/api/users?page=5',
            next: '/api/users?page=2',
        );
        $context = new ResponseContext(
            resourceType: 'users',
            paginationLinks: $links,
        );

        $output = $this->renderer->renderCollection($items, $context);

        self::assertArrayHasKey('links', $output);
        self::assertIsArray($output['links']);
        self::assertSame('/api/users?page=1', $output['links']['first']);
        self::assertSame('/api/users?page=5', $output['links']['last']);
        self::assertSame('/api/users?page=2', $output['links']['next']);
    }

    #[Test]
    public function collectionOmitsEmptyMeta(): void
    {
        $items = [['id' => '1']];
        $context = new ResponseContext();

        $output = $this->renderer->renderCollection($items, $context);

        self::assertArrayNotHasKey('meta', $output);
    }

    #[Test]
    public function collectionMergesCustomMetaWithPaginationMeta(): void
    {
        $items = [['id' => '1']];
        $paginationMeta = new PaginationMeta(perPage: 10, hasMore: false);
        $context = new ResponseContext(
            meta: ['api_version' => 'v1'],
            paginationMeta: $paginationMeta,
        );

        $output = $this->renderer->renderCollection($items, $context);

        self::assertIsArray($output['meta']);
        self::assertSame('v1', $output['meta']['api_version']);
        self::assertSame(10, $output['meta']['per_page']);
    }

    #[Test]
    public function contentType(): void
    {
        self::assertSame('application/json', $this->renderer->contentType());
    }
}
