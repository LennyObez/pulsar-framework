<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Features\SavedViews;

use Pulsar\Extension\Admin\Internal\Storage\SavedViewStoreInterface;

/**
 * Handles CRUD operations on saved views.
 */
final readonly class SavedViewsHandler
{
    public function __construct(
        private SavedViewStoreInterface $store,
    ) {}

    public function execute(SavedViewsRequest $request): SavedViewsResult
    {
        return match ($request->operation) {
            'list' => $this->handleList($request),
            'get' => $this->handleGet($request),
            'save' => $this->handleSave($request),
            'delete' => $this->handleDelete($request),
        };
    }

    private function handleList(SavedViewsRequest $request): SavedViewsResult
    {
        $resourceName = $request->resourceName ?? '';
        $views = $this->store->listForResource($resourceName);

        return new SavedViewsResult(success: true, views: $views);
    }

    private function handleGet(SavedViewsRequest $request): SavedViewsResult
    {
        $viewId = $request->viewId ?? '';
        $view = $this->store->find($viewId);

        return new SavedViewsResult(success: $view !== null, view: $view);
    }

    private function handleSave(SavedViewsRequest $request): SavedViewsResult
    {
        if ($request->view === null) {
            return new SavedViewsResult(success: false);
        }

        $this->store->save($request->view);

        return new SavedViewsResult(success: true, view: $request->view);
    }

    private function handleDelete(SavedViewsRequest $request): SavedViewsResult
    {
        $viewId = $request->viewId ?? '';
        $this->store->delete($viewId);

        return new SavedViewsResult(success: true);
    }
}
