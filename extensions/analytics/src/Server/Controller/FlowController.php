<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\FlowServiceInterface;
use Pulsar\Http\Message\Response;

use function is_string;

/**
 * User flow / behavior flow API endpoint.
 */
#[Internal(reason: 'Analytics API controller')]
final readonly class FlowController
{
    public function __construct(
        private FlowServiceInterface $flowService,
    ) {}

    public function flow(ServerRequestInterface $request): Response
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
        $rawEntryPage = $params['entry_page'] ?? null;
        $entryPage = is_string($rawEntryPage) ? $rawEntryPage : '/';
        $rawDepth = $params['depth'] ?? 3;
        $depth = max(1, min(5, is_numeric($rawDepth) ? (int) $rawDepth : 3));

        $steps = $this->flowService->getFlowFromPage($siteId, $from, $to, $entryPage, $depth);

        return Response::json([
            'data' => array_map(static fn($step) => [
                'source' => $step->source,
                'target' => $step->target,
                'visitors' => $step->visitors,
                'depth' => $step->depth,
            ], $steps),
        ]);
    }

    public function exits(ServerRequestInterface $request): Response
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

        $data = $this->flowService->getExitPages($siteId, $from, $to);

        return Response::json(['data' => $data]);
    }
}
