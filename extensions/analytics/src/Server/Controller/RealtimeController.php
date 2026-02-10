<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Http\Message\Response;

/**
 * Realtime visitor count and active pages API endpoint.
 */
#[Internal(reason: 'Analytics API controller')]
final readonly class RealtimeController
{
    public function __construct(
        private StatsServiceInterface $statsService,
    ) {}

    public function realtime(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $siteId = (string) ($params['site_id'] ?? '');

        if ($siteId === '') {
            return Response::json(['error' => 'site_id is required'], 400);
        }

        $data = $this->statsService->getRealtime($siteId);

        return Response::json($data);
    }
}
