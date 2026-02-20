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
#[Internal(reason: 'Forum admin controller — implementation detail')]
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
     * GET /admin/forum/tags — List all tags.
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
     * GET /admin/forum/tags/{id} — Show a single tag.
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
     * POST /admin/forum/tags — Create a new tag.
     */
    public function create(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.tags.create');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $name = is_string($body['name'] ?? null) ? $body['name'] : '';
        $slug = is_string($body['slug'] ?? null) ? $body['slug'] : '';

        if ($name === '' || $slug === '') {
            return Response::json(['error' => 'Name and slug are required'], 422);
        }

        $description = is_string($body['description'] ?? null) ? $body['description'] : null;

        $tag = $this->tagService->createTag($name, $slug, $description);

        return Response::json(['data' => self::serializeTag($tag)], 201);
    }

    /**
     * PUT /admin/forum/tags/{id} — Update a tag.
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

        if (is_string($body['name'] ?? null) || is_string($body['slug'] ?? null)) {
            $name = is_string($body['name'] ?? null) ? $body['name'] : $tag->name;
            $slug = is_string($body['slug'] ?? null) ? $body['slug'] : $tag->slug;
            $tag = $tag->rename($name, $slug);
        }

        if (is_string($body['description'] ?? null)) {
            $tag = $tag->describe($body['description']);
        }

        $this->tagRepository->save($tag);

        return Response::json(['data' => self::serializeTag($tag)]);
    }

    /**
     * DELETE /admin/forum/tags/{id} — Delete a tag.
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
