<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\SearchAnalyticsServiceInterface;
use Pulsar\Http\Message\Response;

use function is_string;

/**
 * Internal site search analytics API controller.
 */
#[Internal(reason: 'Analytics API controller')]
final readonly class SearchAnalyticsController
{
    public function __construct(
        private SearchAnalyticsServiceInterface $searchAnalyticsService,
    ) {}

    public function overview(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        /** @var mixed $rawSiteId */
        $rawSiteId = $params['site_id'] ?? null;
        $siteId = is_string($rawSiteId) ? $rawSiteId : '';

        if ($siteId === '') {
            return Response::json(['error' => 'site_id is required'], 400);
        }

        /** @var mixed $rawFrom */
        $rawFrom = $params['from'] ?? null;
        /** @var mixed $rawTo */
        $rawTo = $params['to'] ?? null;
        $from = new DateTimeImmutable(is_string($rawFrom) ? $rawFrom : '-30 days');
        $to = new DateTimeImmutable(is_string($rawTo) ? $rawTo : 'now');

        $data = $this->searchAnalyticsService->getOverview($siteId, $from, $to);

        return Response::json($data);
    }

    public function topQueries(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        /** @var mixed $rawSiteId */
        $rawSiteId = $params['site_id'] ?? null;
        $siteId = is_string($rawSiteId) ? $rawSiteId : '';

        if ($siteId === '') {
            return Response::json(['error' => 'site_id is required'], 400);
        }

        /** @var mixed $rawFrom */
        $rawFrom = $params['from'] ?? null;
        /** @var mixed $rawTo */
        $rawTo = $params['to'] ?? null;
        $from = new DateTimeImmutable(is_string($rawFrom) ? $rawFrom : '-30 days');
        $to = new DateTimeImmutable(is_string($rawTo) ? $rawTo : 'now');

        $queries = $this->searchAnalyticsService->getTopQueries($siteId, $from, $to);

        return Response::json([
            'data' => array_map(static fn($q) => [
                'query' => $q->query,
                'count' => $q->count,
                'result_count' => $q->resultCount,
                'click_through_rate' => $q->clickThroughRate,
            ], $queries),
        ]);
    }

    public function zeroResults(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        /** @var mixed $rawSiteId */
        $rawSiteId = $params['site_id'] ?? null;
        $siteId = is_string($rawSiteId) ? $rawSiteId : '';

        if ($siteId === '') {
            return Response::json(['error' => 'site_id is required'], 400);
        }

        /** @var mixed $rawFrom */
        $rawFrom = $params['from'] ?? null;
        /** @var mixed $rawTo */
        $rawTo = $params['to'] ?? null;
        $from = new DateTimeImmutable(is_string($rawFrom) ? $rawFrom : '-30 days');
        $to = new DateTimeImmutable(is_string($rawTo) ? $rawTo : 'now');

        $queries = $this->searchAnalyticsService->getZeroResultQueries($siteId, $from, $to);

        return Response::json([
            'data' => array_map(static fn($q) => [
                'query' => $q->query,
                'count' => $q->count,
            ], $queries),
        ]);
    }
}
