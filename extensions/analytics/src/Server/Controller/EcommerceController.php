<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\EcommerceServiceInterface;
use Pulsar\Http\Message\Response;

use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function min;

/**
 * E-commerce analytics API controller.
 */
#[Internal(reason: 'Analytics API controller')]
final readonly class EcommerceController
{
    public function __construct(
        private EcommerceServiceInterface $ecommerceService,
    ) {}

    public function summary(ServerRequestInterface $request): Response
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

        $data = $this->ecommerceService->getSummary($siteId, $from, $to);

        return Response::json($data);
    }

    public function products(ServerRequestInterface $request): Response
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
        /** @var mixed $rawLimit */
        $rawLimit = $params['limit'] ?? 10;
        $limit = max(1, min(100, (is_int($rawLimit) || is_string($rawLimit)) && is_numeric($rawLimit) ? (int) $rawLimit : 10));

        $data = $this->ecommerceService->getTopProducts($siteId, $from, $to, $limit);

        return Response::json(['data' => $data]);
    }

    public function revenue(ServerRequestInterface $request): Response
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

        $data = $this->ecommerceService->getRevenueTimeseries($siteId, $from, $to);

        return Response::json(['data' => $data]);
    }
}
