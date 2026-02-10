<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Config\AdminConfig;
use Pulsar\Extension\Admin\Features\GlobalSearch\GlobalSearchHandler;
use Pulsar\Extension\Admin\Features\GlobalSearch\GlobalSearchRequest;
use Pulsar\Http\Message\Response;

use function is_string;
use function str_contains;

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

    public function search(ServerRequestInterface $request): Response
    {
        $query = $request->getQueryParams()['q'] ?? null;
        $queryStr = is_string($query) ? $query : '';

        $result = $this->handler->execute(new GlobalSearchRequest(
            query: $queryStr,
        ));

        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json([
                'results' => $result->results,
                'total_matches' => $result->totalMatches,
            ]);
        }

        return Response::html($this->renderView([
            'query' => $queryStr,
            'results' => $result->results,
            'totalMatches' => $result->totalMatches,
            'schema_enabled' => $this->config->schema->enabled,
        ]));
    }

    /**
     * @param array<string, mixed> $templateData
     */
    private function renderView(array $templateData): string
    {
        $title = 'Search results';
        $content = 'search';
        ob_start();
        include __DIR__ . '/../View/templates/admin/layout.php';

        return (string) ob_get_clean();
    }
}
