<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Extension\Analytics\Domain\BreakdownDimension;
use Pulsar\Http\Message\Response;

use function is_string;

/**
 * Breakdown statistics API endpoint (top-N by dimension).
 */
#[Internal(reason: 'Analytics API controller')]
final readonly class BreakdownController
{
    public function __construct(
        private StatsServiceInterface $statsService,
    ) {}

    public function breakdown(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $rawSiteId = $params['site_id'] ?? null;
        $siteId = is_string($rawSiteId) ? $rawSiteId : '';

        if ($siteId === '') {
            return Response::json(['error' => 'site_id is required'], 400);
        }

        $rawDimension = $params['dimension'] ?? null;
        $dimensionStr = is_string($rawDimension) ? $rawDimension : 'page';
        $dimension = BreakdownDimension::tryFrom($dimensionStr);

        if ($dimension === null) {
            return Response::json(['error' => 'Invalid dimension: ' . $dimensionStr], 400);
        }

        $rawFrom = $params['from'] ?? null;
        $rawTo = $params['to'] ?? null;
        $from = new DateTimeImmutable(is_string($rawFrom) ? $rawFrom : '-30 days');
        $to = new DateTimeImmutable(is_string($rawTo) ? $rawTo : 'now');
        $rawLimit = $params['limit'] ?? 10;
        $limit = max(1, min(100, is_numeric($rawLimit) ? (int) $rawLimit : 10));

        $data = $this->statsService->getBreakdown($siteId, $from, $to, $dimension, $limit);

        return Response::json(['data' => $data]);
    }
}
