<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

/**
 * Controller for viewing action history.
 */
#[Internal]
final readonly class ActionHistoryController
{
    public function __construct(
        private readonly ActionHistoryStoreInterface $store,
        private readonly AdminConfig $config,
    ) {}

    public function recent(Request $request): Response
    {
        $limitParam = $request->query('limit');
        $limit = max(1, min(100, is_numeric($limitParam) ? (int) $limitParam : 50));
        $entries = $this->store->recent($limit);

        $data = array_map(
            static fn($e): array => [
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

        if ($request->wantsJson()) {
            return Response::json(['entries' => $data]);
        }

        return Response::html($this->renderHtml('Activity', $data));
    }

    public function forResource(Request $request, string $resource): Response
    {
        $limitParam = $request->query('limit');
        $limit = max(1, min(100, is_numeric($limitParam) ? (int) $limitParam : 50));
        $entries = $this->store->forResource($resource, $limit);

        $data = array_map(
            static fn($e): array => [
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

        $e = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($request->wantsJson()) {
            return Response::json(['entries' => $data]);
        }

        return Response::html($this->renderHtml('Activity: ' . $e($resource), $data));
    }

    /**
     * @param list<array{id: string, action: string, resource: string, record_id: ?string, actor: string, timestamp: int, success: bool, detail: string}> $entries
     */
    private function renderHtml(string $pageTitle, array $entries): string
    {
        ob_start();
        $title = $pageTitle;
        $content = 'activity';
        $templateData = ['entries' => $entries, 'schema_enabled' => $this->config->schema->enabled];
        include __DIR__ . '/../View/templates/admin/layout.php';

        return (string) ob_get_clean();
    }
}
