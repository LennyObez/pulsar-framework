<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Pulsar\Api\Internal;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Admin\Domain\SavedView;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsHandler;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsRequest;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

use function bin2hex;
use function is_array;
use function random_bytes;
use function time;

/**
 * Controller for saved view CRUD.
 */
#[Internal]
final readonly class SavedViewsController
{
    public function __construct(
        private readonly SavedViewsHandler $handler,
    ) {}

    public function list(Request $request, string $resource): Response
    {
        $result = $this->handler->execute(new SavedViewsRequest(
            operation: 'list',
            resourceName: $resource,
        ));

        return Response::json([
            'views' => array_map(
                static fn(SavedView $v): array => [
                    'id' => $v->id,
                    'label' => $v->label,
                    'is_default' => $v->isDefault,
                    'filters' => $v->filters,
                    'sort' => $v->sort,
                    'per_page' => $v->perPage,
                ],
                $result->views,
            ),
        ]);
    }

    public function store(Request $request, string $resource): Response
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->attribute('identity');
        $actor = $identity?->id() ?? 'anonymous';

        $label = $request->input('label', '') ?? '';
        $filters = $request->input('filters', []) ?? [];
        $sort = $request->input('sort', []) ?? [];
        /** @var int $perPage */
        $perPage = (int) ($request->input('per_page', 25) ?? 25);
        /** @var bool $isDefault */
        $isDefault = (bool) ($request->input('is_default', false) ?? false);

        if (!is_array($filters)) {
            $filters = [];
        }
        if (!is_array($sort)) {
            $sort = [];
        }

        $view = new SavedView(
            id: bin2hex(random_bytes(16)),
            resourceName: $resource,
            label: $label,
            filters: $filters,
            sort: $sort,
            perPage: $perPage,
            createdBy: $actor,
            isDefault: $isDefault,
            createdAt: time(),
        );

        $result = $this->handler->execute(new SavedViewsRequest(
            operation: 'save',
            view: $view,
        ));

        return Response::json(
            ['success' => $result->success],
            $result->success ? ResponseStatus::Created : ResponseStatus::InternalServerError,
        );
    }

    public function delete(Request $request, string $resource, string $viewId): Response
    {
        $result = $this->handler->execute(new SavedViewsRequest(
            operation: 'delete',
            viewId: $viewId,
        ));

        return Response::json(['success' => $result->success]);
    }
}
