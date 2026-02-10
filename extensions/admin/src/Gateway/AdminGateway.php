<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Gateway;

use Pulsar\Api\Api;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Domain\ActionResult;
use Pulsar\Extension\Admin\Domain\ExportFormat;
use Pulsar\Extension\Admin\Domain\SavedView;
use Pulsar\Extension\Admin\Features\BulkAction\BulkActionHandler;
use Pulsar\Extension\Admin\Features\BulkAction\BulkActionRequest;
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceHandler;
use Pulsar\Extension\Admin\Features\CreateResource\CreateResourceRequest;
use Pulsar\Extension\Admin\Features\Dashboard\DashboardHandler;
use Pulsar\Extension\Admin\Features\Dashboard\DashboardRequest;
use Pulsar\Extension\Admin\Features\Dashboard\DashboardResult;
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceHandler;
use Pulsar\Extension\Admin\Features\DeleteResource\DeleteResourceRequest;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceHandler;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceRequest;
use Pulsar\Extension\Admin\Features\ExportResource\ExportResourceResult;
use Pulsar\Extension\Admin\Features\GlobalSearch\GlobalSearchHandler;
use Pulsar\Extension\Admin\Features\GlobalSearch\GlobalSearchRequest;
use Pulsar\Extension\Admin\Features\GlobalSearch\GlobalSearchResult;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceHandler;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceRequest;
use Pulsar\Extension\Admin\Features\ListResource\ListResourceResult;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsHandler;
use Pulsar\Extension\Admin\Features\SavedViews\SavedViewsRequest;
use Pulsar\Extension\Admin\Features\UpdateResource\UpdateResourceHandler;
use Pulsar\Extension\Admin\Features\UpdateResource\UpdateResourceRequest;
use Pulsar\Extension\Admin\Features\ViewResource\ViewResourceHandler;
use Pulsar\Extension\Admin\Features\ViewResource\ViewResourceRequest;
use Pulsar\Extension\Admin\Features\ViewResource\ViewResourceResult;

/**
 * Orchestrator facade for the admin extension.
 *
 * Delegates to feature handlers for each operation, providing a single
 * entry point for programmatic admin interactions.
 */
#[Api(since: '1.0.0')]
final class AdminGateway
{
    public function __construct(
        private readonly ResourceRegistryInterface $registry,
        private readonly ListResourceHandler $listHandler,
        private readonly ViewResourceHandler $viewHandler,
        private readonly CreateResourceHandler $createHandler,
        private readonly UpdateResourceHandler $updateHandler,
        private readonly DeleteResourceHandler $deleteHandler,
        private readonly BulkActionHandler $bulkActionHandler,
        private readonly ExportResourceHandler $exportHandler,
        private readonly GlobalSearchHandler $searchHandler,
        private readonly DashboardHandler $dashboardHandler,
        private readonly SavedViewsHandler $savedViewsHandler,
    ) {}

    public function registerResource(DataResourceInterface $resource): void
    {
        $this->registry->register($resource);
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, string> $sort
     */
    public function listRecords(
        string $resourceName,
        array $filters = [],
        array $sort = [],
        int $page = 1,
        int $perPage = 25,
    ): ListResourceResult {
        return $this->listHandler->execute(new ListResourceRequest(
            resourceName: $resourceName,
            filters: $filters,
            sort: $sort,
            page: $page,
            perPage: $perPage,
        ));
    }

    public function viewRecord(string $resourceName, string $id): ViewResourceResult
    {
        return $this->viewHandler->execute(new ViewResourceRequest(
            resourceName: $resourceName,
            id: $id,
        ));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createRecord(string $resourceName, array $data, MutationContext $context): ActionResult
    {
        return $this->createHandler->execute(new CreateResourceRequest(
            resourceName: $resourceName,
            data: $data,
            context: $context,
        ))->result;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateRecord(string $resourceName, string $id, array $data, MutationContext $context): ActionResult
    {
        return $this->updateHandler->execute(new UpdateResourceRequest(
            resourceName: $resourceName,
            id: $id,
            data: $data,
            context: $context,
        ))->result;
    }

    public function deleteRecord(string $resourceName, string $id, MutationContext $context): ActionResult
    {
        return $this->deleteHandler->execute(new DeleteResourceRequest(
            resourceName: $resourceName,
            id: $id,
            context: $context,
        ))->result;
    }

    /**
     * @param list<string> $ids
     * @param array<string, mixed> $parameters
     */
    public function bulkAction(
        string $resourceName,
        string $action,
        array $ids,
        array $parameters,
        MutationContext $context,
    ): ActionResult {
        return $this->bulkActionHandler->execute(new BulkActionRequest(
            resourceName: $resourceName,
            action: $action,
            ids: $ids,
            parameters: $parameters,
            context: $context,
        ))->result;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function export(string $resourceName, ExportFormat $format, array $filters = []): ExportResourceResult
    {
        return $this->exportHandler->execute(new ExportResourceRequest(
            resourceName: $resourceName,
            format: $format,
            filters: $filters,
        ));
    }

    public function search(string $query): GlobalSearchResult
    {
        return $this->searchHandler->execute(new GlobalSearchRequest(query: $query));
    }

    public function dashboard(): DashboardResult
    {
        return $this->dashboardHandler->execute(new DashboardRequest());
    }

    /**
     * @return list<SavedView>
     */
    public function savedViews(string $resourceName): array
    {
        return $this->savedViewsHandler->execute(new SavedViewsRequest(
            operation: 'list',
            resourceName: $resourceName,
        ))->views;
    }

    public function saveSavedView(SavedView $view): void
    {
        $this->savedViewsHandler->execute(new SavedViewsRequest(
            operation: 'save',
            view: $view,
        ));
    }

    public function deleteSavedView(string $viewId): void
    {
        $this->savedViewsHandler->execute(new SavedViewsRequest(
            operation: 'delete',
            viewId: $viewId,
        ));
    }
}
