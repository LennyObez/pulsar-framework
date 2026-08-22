<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Page;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Http\Controller\Page\TagPageController;
use Pulsar\Extension\Forum\Tag\Tag;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(TagPageController::class)]
final class TagPageControllerTest extends TestCase
{
    #[Test]
    public function indexReturnsAllTags(): void
    {
        $tag1 = new Tag(id: 'tag-1', slug: 'php', name: 'PHP', description: 'PHP language', usageCount: 42);
        $tag2 = new Tag(id: 'tag-2', slug: 'rust', name: 'Rust', description: 'Rust language', usageCount: 15);

        $tagRepo = $this->createStub(TagRepositoryInterface::class);
        $tagRepo->method('findAll')->willReturn([$tag1, $tag2]);

        $controller = new TagPageController(
            tagRepository: $tagRepo,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/tags',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Tags', $body['page_title']);
        self::assertCount(2, $body['tags']);
        self::assertSame('PHP', $body['tags'][0]['name']);
        self::assertSame(42, $body['tags'][0]['usage_count']);
        self::assertSame('Rust', $body['tags'][1]['name']);
    }

    #[Test]
    public function indexWithNoTagsReturnsEmptyArray(): void
    {
        $tagRepo = $this->createStub(TagRepositoryInterface::class);
        $tagRepo->method('findAll')->willReturn([]);

        $controller = new TagPageController(
            tagRepository: $tagRepo,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/tags',
            headers: ['Accept' => 'application/json'],
        );

        $response = $controller->index($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([], $body['tags']);
    }
}
