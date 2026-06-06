<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Tickets\Contracts\TicketCategoryRepositoryInterface;
use Pulsar\Extension\Tickets\Domain\TicketCategory;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function bin2hex;
use function is_string;
use function random_bytes;
use function trim;

/**
 * Admin CRUD controller for ticket categories.
 */
#[Internal(reason: 'Ticket admin controller; implementation detail')]
final readonly class TicketCategoryController
{
    use RendersAdminView;
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function __construct(
        private TicketCategoryRepositoryInterface $categoryRepository,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/tickets/categories: List all categories.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'tickets.admin.categories');

        $categories = $this->categoryRepository->findAll();

        $data = [
            'categories' => array_map(static fn(TicketCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'description' => $c->description,
                'parent_id' => $c->parentId,
                'sort_order' => $c->sortOrder,
            ], $categories),
        ];

        return $this->respondWithView($request, 'admin.tickets.categories', $data);
    }

    /**
     * POST /admin/tickets/categories: Create a new category.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function create(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'tickets.admin.categories.create');

        /** @var array<string, mixed> $body */
        $body = $request->getParsedBody() ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $slug = trim((string) ($body['slug'] ?? ''));
        $description = isset($body['description']) && is_string($body['description']) && trim($body['description']) !== ''
            ? trim($body['description'])
            : null;
        $parentId = isset($body['parent_id']) && is_string($body['parent_id']) && $body['parent_id'] !== ''
            ? $body['parent_id']
            : null;
        $sortOrder = (int) ($body['sort_order'] ?? 0);

        if ($name === '' || $slug === '') {
            return Response::json(['error' => 'Name and slug are required.'], 422);
        }

        $category = TicketCategory::create(
            id: bin2hex(random_bytes(16)),
            name: $name,
            slug: $slug,
            description: $description,
            parentId: $parentId,
            sortOrder: $sortOrder,
        );

        $this->categoryRepository->save($category);

        return Response::json([
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
        ], 201);
    }

    /**
     * PUT /admin/tickets/categories/{id}: Update a category.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function update(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'tickets.admin.categories.update');

        /** @var string $id */
        $id = $request->getAttribute('id', '');
        $category = $this->categoryRepository->findById($id);

        if ($category === null) {
            return Response::json(['error' => 'Category not found.'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = $request->getParsedBody() ?? [];

        if (isset($body['name'], $body['slug'])) {
            $category = $category->rename(
                trim((string) $body['name']),
                trim((string) $body['slug']),
            );
        }

        if (isset($body['description'])) {
            $description = is_string($body['description']) && trim($body['description']) !== ''
                ? trim($body['description'])
                : null;
            $category = $category->describe($description);
        }

        if (isset($body['sort_order'])) {
            $category = $category->reorder((int) $body['sort_order']);
        }

        $this->categoryRepository->save($category);

        return Response::json([
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
        ]);
    }

    /**
     * DELETE /admin/tickets/categories/{id}: Delete a category.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function delete(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'tickets.admin.categories.delete');

        /** @var string $id */
        $id = $request->getAttribute('id', '');
        $category = $this->categoryRepository->findById($id);

        if ($category === null) {
            return Response::json(['error' => 'Category not found.'], 404);
        }

        $this->categoryRepository->delete($category);

        return Response::json(['deleted' => true]);
    }
}
