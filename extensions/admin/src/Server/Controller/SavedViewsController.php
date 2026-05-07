<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Domain\SavedView;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsHandler;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsRequest;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;

use function bin2hex;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function random_bytes;
use function time;

/**
 * Controller for saved view CRUD.
 */
#[Internal]
final readonly class SavedViewsController
{
    use ExtractsRequestActor;

    public function __construct(
        private SavedViewsHandler $handler,
    ) {}

    public function list(ServerRequestInterface $request, string $resource): Response
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

    public function store(ServerRequestInterface $request, string $resource): Response
    {
        $actor = $this->resolveActor($request);

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $labelRaw */
        $labelRaw = $body['label'] ?? '';
        $label = is_string($labelRaw) ? $labelRaw : '';
        /** @var mixed $filters */
        $filters = $body['filters'] ?? [];
        /** @var mixed $sort */
        $sort = $body['sort'] ?? [];
        /** @var mixed $perPageRaw */
        $perPageRaw = $body['per_page'] ?? 25;
        $perPage = (is_int($perPageRaw) || is_string($perPageRaw)) && is_numeric($perPageRaw) ? (int) $perPageRaw : 25;
        $isDefault = (bool) ($body['is_default'] ?? false);

        if (!is_array($filters)) {
            $filters = [];
        }
        if (!is_array($sort)) {
            $sort = [];
        }

        /** @var array<string, mixed> $validFilters */
        $validFilters = $filters;
        /** @var array<string, string> $validSort */
        $validSort = $sort;
        $view = new SavedView(
            id: bin2hex(random_bytes(16)),
            resourceName: $resource,
            label: $label,
            filters: $validFilters,
            sort: $validSort,
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
            $result->success ? ResponseStatus::Created->value : ResponseStatus::InternalServerError->value,
        );
    }

    public function delete(ServerRequestInterface $request, string $resource, string $viewId): Response
    {
        $result = $this->handler->execute(new SavedViewsRequest(
            operation: 'delete',
            viewId: $viewId,
        ));

        return Response::json(['success' => $result->success]);
    }
}
