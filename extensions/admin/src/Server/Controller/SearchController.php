<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Features\GlobalSearch\GlobalSearchHandler;
use Pulsar\Extension\Admin\Features\GlobalSearch\GlobalSearchRequest;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

use function is_string;

/**
 * Controller for global admin search.
 */
#[Internal]
final readonly class SearchController
{
    public function __construct(
        private GlobalSearchHandler $handler,
        private AdminConfig $config,
    ) {}

    public function search(Request $request): Response
    {
        $query = $request->query('q');
        $queryStr = is_string($query) ? $query : '';

        $result = $this->handler->execute(new GlobalSearchRequest(
            query: $queryStr,
        ));

        if ($request->wantsJson()) {
            return Response::json([
                'results' => $result->results,
                'total_matches' => $result->totalMatches,
            ]);
        }

        return Response::html($this->renderView('Search Results', 'search', [
            'query' => $queryStr,
            'results' => $result->results,
            'totalMatches' => $result->totalMatches,
            'schema_enabled' => $this->config->schema->enabled,
        ]));
    }

    /**
     * @param array<string, mixed> $templateData
     */
    private function renderView(string $title, string $content, array $templateData): string
    {
        ob_start();
        include __DIR__ . '/../View/templates/admin/layout.php';

        return (string) ob_get_clean();
    }
}
