<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Http\Message\Response;

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
        $siteId = (string) ($params['site_id'] ?? '');

        if ($siteId === '') {
            return Response::json(['error' => 'site_id is required'], 400);
        }

        $from = new DateTimeImmutable((string) ($params['from'] ?? '-30 days'));
        $to = new DateTimeImmutable((string) ($params['to'] ?? 'now'));
        $metric = (string) ($params['metric'] ?? 'visitors');
        $interval = (string) ($params['interval'] ?? 'day');

        $data = $this->statsService->getTimeseries($siteId, $from, $to, $metric, $interval);

        return Response::json(['data' => $data]);
    }
}
