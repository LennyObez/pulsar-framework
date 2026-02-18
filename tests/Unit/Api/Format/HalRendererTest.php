<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Format;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Format\HalRenderer;
use Pulsar\Api\Format\ResponseContext;
use Pulsar\Api\Pagination\PaginationLinks;
use Pulsar\Api\Pagination\PaginationMeta;

#[CoversClass(HalRenderer::class)]
final class HalRendererTest extends TestCase
{
    private HalRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new HalRenderer();
    }

    #[Test]
    public function rendersSingleResourceWithLinks(): void
    {
        $data = ['id' => '1', 'name' => 'Alice'];
        $context = new ResponseContext(resourceType: 'users');

        $output = $this->renderer->render($data, $context);

        self::assertArrayHasKey('_links', $output);
        self::assertIsArray($output['_links']);
        self::assertArrayHasKey('self', $output['_links']);
        self::assertIsArray($output['_links']['self']);
        self::assertArrayHasKey('href', $output['_links']['self']);
        self::assertSame('1', $output['id']);
        self::assertSame('Alice', $output['name']);
    }

    #[Test]
    public function singleResourceRemovesMeta(): void
    {
        $data = ['id' => '1', 'name' => 'Alice', '_meta' => ['redactions' => ['ssn' => 'denied']]];
        $context = new ResponseContext(resourceType: 'users');

        $output = $this->renderer->render($data, $context);

        self::assertArrayNotHasKey('_meta', $output);
    }

    #[Test]
    public function rendersCollectionWithEmbedded(): void
    {
        $items = [
            ['id' => '1', 'name' => 'Alice'],
            ['id' => '2', 'name' => 'Bob'],
        ];
        $context = new ResponseContext(resourceType: 'users');

        $output = $this->renderer->renderCollection($items, $context);

        self::assertArrayHasKey('_links', $output);
        self::assertArrayHasKey('_embedded', $output);
        self::assertIsArray($output['_embedded']);
        self::assertArrayHasKey('users', $output['_embedded']);
        self::assertIsArray($output['_embedded']['users']);
        self::assertCount(2, $output['_embedded']['users']);
    }

    #[Test]
    public function collectionUsesResourceTypeForEmbeddedKey(): void
    {
        $items = [['id' => '1']];
        $context = new ResponseContext(resourceType: 'articles');

        $output = $this->renderer->renderCollection($items, $context);

        self::assertIsArray($output['_embedded']);
        self::assertArrayHasKey('articles', $output['_embedded']);
    }

    #[Test]
    public function collectionDefaultsToItemsWhenNoResourceType(): void
    {
        $items = [['id' => '1']];
        $context = new ResponseContext();

        $output = $this->renderer->renderCollection($items, $context);

        self::assertIsArray($output['_embedded']);
        self::assertArrayHasKey('items', $output['_embedded']);
    }

    #[Test]
    public function collectionIncludesPaginationLinks(): void
    {
        $items = [['id' => '1']];
        $links = new PaginationLinks(
            first: '/api/users?page=1',
            last: '/api/users?page=5',
            next: '/api/users?page=2',
            prev: '/api/users?page=0',
        );
        $context = new ResponseContext(
            resourceType: 'users',
            paginationLinks: $links,
        );

        $output = $this->renderer->renderCollection($items, $context);

        self::assertIsArray($output['_links']);
        self::assertArrayHasKey('first', $output['_links']);
        self::assertIsArray($output['_links']['first']);
        self::assertSame('/api/users?page=1', $output['_links']['first']['href']);
        self::assertArrayHasKey('last', $output['_links']);
        self::assertIsArray($output['_links']['last']);
        self::assertSame('/api/users?page=5', $output['_links']['last']['href']);
        self::assertArrayHasKey('next', $output['_links']);
        self::assertIsArray($output['_links']['next']);
        self::assertSame('/api/users?page=2', $output['_links']['next']['href']);
        self::assertArrayHasKey('prev', $output['_links']);
        self::assertIsArray($output['_links']['prev']);
        self::assertSame('/api/users?page=0', $output['_links']['prev']['href']);
    }

    #[Test]
    public function collectionIncludesPaginationMeta(): void
    {
        $items = [['id' => '1']];
        $paginationMeta = new PaginationMeta(perPage: 10, hasMore: true, total: 42);
        $context = new ResponseContext(
            resourceType: 'users',
            paginationMeta: $paginationMeta,
        );

        $output = $this->renderer->renderCollection($items, $context);

        self::assertSame(10, $output['per_page']);
        self::assertTrue($output['has_more']);
        self::assertSame(42, $output['total']);
    }

    #[Test]
    public function collectionAlwaysHasSelfLink(): void
    {
        $items = [['id' => '1']];
        $context = new ResponseContext(resourceType: 'users');

        $output = $this->renderer->renderCollection($items, $context);

        self::assertIsArray($output['_links']);
        self::assertArrayHasKey('self', $output['_links']);
    }

    #[Test]
    public function contentType(): void
    {
        self::assertSame('application/hal+json', $this->renderer->contentType());
    }
}
