<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Extension\Analytics\Domain\BreakdownDimension;
use Pulsar\Http\Message\Response;

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
        $siteId = (string) ($params['site_id'] ?? '');

        if ($siteId === '') {
            return Response::json(['error' => 'site_id is required'], 400);
        }

        $dimensionStr = (string) ($params['dimension'] ?? 'page');
        $dimension = BreakdownDimension::tryFrom($dimensionStr);

        if ($dimension === null) {
            return Response::json(['error' => 'Invalid dimension: ' . $dimensionStr], 400);
        }

        $from = new DateTimeImmutable((string) ($params['from'] ?? '-30 days'));
        $to = new DateTimeImmutable((string) ($params['to'] ?? 'now'));
        $limit = max(1, min(100, (int) ($params['limit'] ?? 10)));

        $data = $this->statsService->getBreakdown($siteId, $from, $to, $dimension, $limit);

        return Response::json(['data' => $data]);
    }
}
