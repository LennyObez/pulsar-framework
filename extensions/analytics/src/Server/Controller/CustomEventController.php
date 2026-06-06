<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\CustomEventServiceInterface;
use Pulsar\Http\Message\Response;

use function is_string;

/**
 * Custom event exploration API controller.
 */
#[Internal(reason: 'Analytics API controller')]
final readonly class CustomEventController
{
    public function __construct(
        private CustomEventServiceInterface $customEventService,
    ) {}
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function names(ServerRequestInterface $request): Response
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

        $data = $this->customEventService->getEventNames($siteId, $from, $to);

        return Response::json(['data' => $data]);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function properties(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        /** @var mixed $rawSiteId */
        $rawSiteId = $params['site_id'] ?? null;
        $siteId = is_string($rawSiteId) ? $rawSiteId : '';
        /** @var mixed $rawEventName */
        $rawEventName = $params['event_name'] ?? null;
        $eventName = is_string($rawEventName) ? $rawEventName : '';

        if ($siteId === '' || $eventName === '') {
            return Response::json(['error' => 'site_id and event_name are required'], 400);
        }

        /** @var mixed $rawFrom */
        $rawFrom = $params['from'] ?? null;
        /** @var mixed $rawTo */
        $rawTo = $params['to'] ?? null;
        $from = new DateTimeImmutable(is_string($rawFrom) ? $rawFrom : '-30 days');
        $to = new DateTimeImmutable(is_string($rawTo) ? $rawTo : 'now');

        $data = $this->customEventService->getEventProperties($siteId, $from, $to, $eventName);

        return Response::json(['data' => $data]);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function timeseries(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        /** @var mixed $rawSiteId */
        $rawSiteId = $params['site_id'] ?? null;
        $siteId = is_string($rawSiteId) ? $rawSiteId : '';
        /** @var mixed $rawEventName */
        $rawEventName = $params['event_name'] ?? null;
        $eventName = is_string($rawEventName) ? $rawEventName : '';

        if ($siteId === '' || $eventName === '') {
            return Response::json(['error' => 'site_id and event_name are required'], 400);
        }

        /** @var mixed $rawFrom */
        $rawFrom = $params['from'] ?? null;
        /** @var mixed $rawTo */
        $rawTo = $params['to'] ?? null;
        $from = new DateTimeImmutable(is_string($rawFrom) ? $rawFrom : '-30 days');
        $to = new DateTimeImmutable(is_string($rawTo) ? $rawTo : 'now');

        $data = $this->customEventService->getEventTimeseries($siteId, $from, $to, $eventName);

        return Response::json(['data' => $data]);
    }
}
