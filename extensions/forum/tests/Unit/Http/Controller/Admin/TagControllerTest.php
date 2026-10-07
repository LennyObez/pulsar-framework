<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Http\Controller\Admin\TagController;
use Pulsar\Extension\Forum\Service\TagServiceInterface;
use Pulsar\Extension\Forum\Tag\Tag;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(TagController::class)]
final class TagControllerTest extends TestCase
{
    private function makeIdentity(): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('admin-1');
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    private function makeAllowGate(): GateInterface
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        return $gate;
    }

    private function makeTag(string $id = 'tag-1'): Tag
    {
        return new Tag(
            id: $id,
            slug: 'php',
            name: 'PHP',
            description: 'PHP programming',
            usageCount: 42,
        );
    }

    private function makeController(
        ?GateInterface $gate = null,
        ?TagRepositoryInterface $tagRepo = null,
        ?TagServiceInterface $tagService = null,
    ): TagController {
        return new TagController(
            tagRepository: $tagRepo ?? $this->createStub(TagRepositoryInterface::class),
            tagService: $tagService ?? $this->createStub(TagServiceInterface::class),
            gate: $gate ?? $this->makeAllowGate(),
        );
    }

    #[Test]
    public function indexWithoutPermissionThrows(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->makeController(gate: $gate);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/tags',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $this->expectException(AuthorizationException::class);

        $controller->index($request);
    }

    #[Test]
    public function indexReturnsTags(): void
    {
        $tag = $this->makeTag();

        $tagRepo = $this->createStub(TagRepositoryInterface::class);
        $tagRepo->method('findAll')->willReturn([$tag]);

        $controller = $this->makeController(tagRepo: $tagRepo);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/tags',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame('PHP', $body['data'][0]['name']);
    }

    #[Test]
    public function showReturns404WhenNotFound(): void
    {
        $tagRepo = $this->createStub(TagRepositoryInterface::class);
        $tagRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(tagRepo: $tagRepo);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/tags/nonexistent',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->show($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function showReturnsTag(): void
    {
        $tag = $this->makeTag();

        $tagRepo = $this->createStub(TagRepositoryInterface::class);
        $tagRepo->method('findById')->willReturn($tag);

        $controller = $this->makeController(tagRepo: $tagRepo);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/tags/tag-1',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->show($request, 'tag-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('tag-1', $body['tag']['id']);
    }

    #[Test]
    public function createWithMissingFieldsReturns422(): void
    {
        $controller = $this->makeController();

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/forum/tags',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['name' => '', 'slug' => '']);

        $response = $controller->create($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function createReturns201OnSuccess(): void
    {
        $tag = $this->makeTag();

        $tagService = $this->createStub(TagServiceInterface::class);
        $tagService->method('createTag')->willReturn($tag);

        $controller = $this->makeController(tagService: $tagService);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/admin/forum/tags',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['name' => 'PHP', 'slug' => 'php', 'description' => 'PHP programming']);

        $response = $controller->create($request);

        self::assertSame(201, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('PHP', $body['data']['name']);
    }

    #[Test]
    public function updateReturns404WhenNotFound(): void
    {
        $tagRepo = $this->createStub(TagRepositoryInterface::class);
        $tagRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(tagRepo: $tagRepo);

        $request = new ServerRequest(
            method: 'PUT',
            uri: '/admin/forum/tags/nonexistent',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity())
            ->withParsedBody(['name' => 'Updated']);

        $response = $controller->update($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function deleteReturns404WhenNotFound(): void
    {
        $tagRepo = $this->createStub(TagRepositoryInterface::class);
        $tagRepo->method('findById')->willReturn(null);

        $controller = $this->makeController(tagRepo: $tagRepo);

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/admin/forum/tags/nonexistent',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->delete($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function deleteReturnsSuccess(): void
    {
        $tag = $this->makeTag();

        $tagRepo = $this->createStub(TagRepositoryInterface::class);
        $tagRepo->method('findById')->willReturn($tag);

        $controller = $this->makeController(tagRepo: $tagRepo);

        $request = new ServerRequest(
            method: 'DELETE',
            uri: '/admin/forum/tags/tag-1',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->delete($request, 'tag-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('deleted', $body['data']['status']);
    }
}
