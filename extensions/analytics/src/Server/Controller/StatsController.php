<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Http\Message\Response;

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
        $siteId = (string) ($params['site_id'] ?? '');

        if ($siteId === '') {
            return Response::json(['error' => 'site_id is required'], 400);
        }

        $from = new DateTimeImmutable((string) ($params['from'] ?? '-30 days'));
        $to = new DateTimeImmutable((string) ($params['to'] ?? 'now'));
        /** @var array<string, string> $filters */
        $filters = (array) ($params['filters'] ?? []);

        $data = $this->statsService->getAggregate($siteId, $from, $to, $filters);

        return Response::json($data);
    }
}
