<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Http\Message\Response;

use function is_string;

/**
 * Time-series statistics API endpoint.
 */
#[Internal(reason: 'Analytics API controller')]
final readonly class TimeseriesController
{
    public function __construct(
        private StatsServiceInterface $statsService,
    ) {}

    public function timeseries(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $rawSiteId = $params['site_id'] ?? null;
        $siteId = is_string($rawSiteId) ? $rawSiteId : '';

        if ($siteId === '') {
            return Response::json(['error' => 'site_id is required'], 400);
        }

        $rawFrom = $params['from'] ?? null;
        $rawTo = $params['to'] ?? null;
        $from = new DateTimeImmutable(is_string($rawFrom) ? $rawFrom : '-30 days');
        $to = new DateTimeImmutable(is_string($rawTo) ? $rawTo : 'now');
        $rawMetric = $params['metric'] ?? null;
        $metric = is_string($rawMetric) ? $rawMetric : 'visitors';
        $rawInterval = $params['interval'] ?? null;
        $interval = is_string($rawInterval) ? $rawInterval : 'day';

        $data = $this->statsService->getTimeseries($siteId, $from, $to, $metric, $interval);

        return Response::json(['data' => $data]);
    }
}
