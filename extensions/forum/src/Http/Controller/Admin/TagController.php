<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Forum\Service\TagServiceInterface;
use Pulsar\Extension\Forum\Tag\Tag;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_string;

/**
 * Admin controller for tag CRUD.
 */
#[Internal(reason: 'Forum admin controller; implementation detail')]
final readonly class TagController
{
    use RendersAdminView;

    public function __construct(
        private TagRepositoryInterface $tagRepository,
        private TagServiceInterface $tagService,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/forum/tags: List all tags.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.tags');

        $tags = $this->tagRepository->findAll();

        $data = [
            'data' => array_map(self::serializeTag(...), $tags),
        ];

        return $this->respondWithView($request, 'admin.forum.tags.index', $data);
    }

    /**
     * GET /admin/forum/tags/{id}: Show a single tag.
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.tags');

        $tag = $this->tagRepository->findById($id);

        if ($tag === null) {
            return Response::json(['error' => 'Tag not found'], 404);
        }

        return $this->respondWithView($request, 'admin.forum.tags.show', [
            'tag' => self::serializeTag($tag),
        ]);
    }

    /**
     * POST /admin/forum/tags: Create a new tag.
     */
    public function create(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.tags.create');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawName */
        $rawName = $body['name'] ?? null;
        $name = is_string($rawName) ? $rawName : '';
        /** @var mixed $rawSlug */
        $rawSlug = $body['slug'] ?? null;
        $slug = is_string($rawSlug) ? $rawSlug : '';

        if ($name === '' || $slug === '') {
            return Response::json(['error' => 'Name and slug are required'], 422);
        }

        /** @var mixed $rawDescription */
        $rawDescription = $body['description'] ?? null;
        $description = is_string($rawDescription) ? $rawDescription : null;

        $tag = $this->tagService->createTag($name, $slug, $description);

        return Response::json(['data' => self::serializeTag($tag)], 201);
    }

    /**
     * PUT /admin/forum/tags/{id}: Update a tag.
     */
    public function update(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.tags.update');

        $tag = $this->tagRepository->findById($id);

        if ($tag === null) {
            return Response::json(['error' => 'Tag not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawName */
        $rawName = $body['name'] ?? null;
        /** @var mixed $rawSlug */
        $rawSlug = $body['slug'] ?? null;

        if (is_string($rawName) || is_string($rawSlug)) {
            $name = is_string($rawName) ? $rawName : $tag->name;
            $slug = is_string($rawSlug) ? $rawSlug : $tag->slug;
            $tag = $tag->rename($name, $slug);
        }

        /** @var mixed $rawDescription */
        $rawDescription = $body['description'] ?? null;
        if (is_string($rawDescription)) {
            $tag = $tag->describe($rawDescription);
        }

        $this->tagRepository->save($tag);

        return Response::json(['data' => self::serializeTag($tag)]);
    }

    /**
     * DELETE /admin/forum/tags/{id}: Delete a tag.
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.tags.delete');

        $tag = $this->tagRepository->findById($id);

        if ($tag === null) {
            return Response::json(['error' => 'Tag not found'], 404);
        }

        $this->tagRepository->delete($tag);

        return Response::json(['data' => ['id' => $id, 'status' => 'deleted']]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeTag(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'slug' => $tag->slug,
            'name' => $tag->name,
            'description' => $tag->description,
            'usage_count' => $tag->usageCount,
        ];
    }
}
