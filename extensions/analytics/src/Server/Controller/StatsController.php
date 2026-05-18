<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Http\Message\Response;

use function is_array;
use function is_string;

/**
 * Aggregate statistics API endpoint.
 */
#[Internal(reason: 'Analytics API controller')]
final readonly class StatsController
{
    public function __construct(
        private StatsServiceInterface $statsService,
    ) {}

    public function aggregate(ServerRequestInterface $request): Response
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
        /** @var array<string, string> $filters */
        $filters = is_array($params['filters'] ?? null) ? $params['filters'] : [];

        $data = $this->statsService->getAggregate($siteId, $from, $to, $filters);

        return Response::json($data);
    }
}
