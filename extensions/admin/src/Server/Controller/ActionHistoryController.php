<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryEntry;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;
use Pulsar\Http\Message\Response;

use function str_contains;

/**
 * Controller for viewing action history.
 */
#[Internal]
final readonly class ActionHistoryController
{
    use RendersAdminLayout;

    public function __construct(
        private ActionHistoryStoreInterface $store,
        private AdminConfig $config,
    ) {}

    public function recent(ServerRequestInterface $request): Response
    {
        $limit = $this->parseLimitParam($request);
        $entries = $this->store->recent($limit);
        $data = $this->serializeEntries($entries);

        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json(['entries' => $data]);
        }

        return Response::html($this->renderHtml('Activity', $data));
    }

    public function forResource(ServerRequestInterface $request, string $resource): Response
    {
        $limit = $this->parseLimitParam($request);
        $entries = $this->store->forResource($resource, $limit);
        $data = $this->serializeEntries($entries);

        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json(['entries' => $data]);
        }

        $escapedResource = htmlspecialchars($resource);

        return Response::html($this->renderHtml('Activity: ' . $escapedResource, $data));
    }

    private function parseLimitParam(ServerRequestInterface $request): int
    {
        /** @var mixed $limitParam */
        $limitParam = $request->getQueryParams()['limit'] ?? null;

        return max(1, min(100, is_numeric($limitParam) ? (int) $limitParam : 50));
    }

    /**
     * @param list<ActionHistoryEntry> $entries
     * @return list<array{id: string, action: string, resource: string, record_id: ?string, actor: string, timestamp: int, success: bool, detail: string}>
     */
    private function serializeEntries(array $entries): array
    {
        /** @var list<array{id: string, action: string, resource: string, record_id: ?string, actor: string, timestamp: int, success: bool, detail: string}> */
        return array_map(
            static fn(ActionHistoryEntry $e): array => [
                'id' => $e->id,
                'action' => $e->action,
                'resource' => $e->resourceName,
                'record_id' => $e->recordId,
                'actor' => $e->actor,
                'timestamp' => $e->timestamp,
                'success' => $e->success,
                'detail' => $e->detail,
            ],
            $entries,
        );
    }

    /**
     * @param list<array{id: string, action: string, resource: string, record_id: ?string, actor: string, timestamp: int, success: bool, detail: string}> $entries
     */
    private function renderHtml(string $pageTitle, array $entries): string
    {
        return $this->renderAdminView($pageTitle, 'activity', [
            'entries' => $entries,
            'schema_enabled' => $this->config->schema->enabled,
        ]);
    }
}
