<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\SegmentServiceInterface;
use Pulsar\Extension\Analytics\Domain\SegmentDimension;
use Pulsar\Extension\Analytics\Domain\SegmentFilter;
use Pulsar\Extension\Analytics\Domain\SegmentOperator;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;
use Pulsar\Http\Message\Response;

use function is_array;
use function is_string;

/**
 * Audience segment API controller.
 */
#[Internal(reason: 'Analytics API controller')]
final readonly class SegmentController
{
    public function __construct(
        private SegmentServiceInterface $segmentService,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $rawSiteId = $params['site_id'] ?? null;
        $siteId = is_string($rawSiteId) ? $rawSiteId : '';

        if ($siteId === '') {
            return Response::json(['error' => 'site_id is required'], 400);
        }

        $segments = $this->segmentService->listForSite($siteId);

        return Response::json([
            'data' => array_map(static fn($s) => [
                'id' => $s->id,
                'site_id' => $s->siteId,
                'name' => $s->name,
                'filters' => array_map(static fn($f) => [
                    'dimension' => $f->dimension->value,
                    'operator' => $f->operator->value,
                    'value' => $f->value,
                ], $s->filters),
                'created_at' => $s->createdAt->format('c'),
            ], $segments),
        ]);
    }

    public function create(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $siteId = is_string($body['site_id'] ?? null) ? $body['site_id'] : '';
        $name = is_string($body['name'] ?? null) ? $body['name'] : '';
        $rawFilters = is_array($body['filters'] ?? null) ? $body['filters'] : [];

        if ($siteId === '' || $name === '' || $rawFilters === []) {
            return Response::json(['error' => 'site_id, name, and filters are required'], 400);
        }

        $filters = [];

        /** @var array<string, mixed> $rawFilter */
        foreach ($rawFilters as $rawFilter) {
            if (!is_array($rawFilter)) {
                continue;
            }

            $dimStr = is_string($rawFilter['dimension'] ?? null) ? $rawFilter['dimension'] : '';
            $opStr = is_string($rawFilter['operator'] ?? null) ? $rawFilter['operator'] : '';
            $value = is_string($rawFilter['value'] ?? null) ? $rawFilter['value'] : '';

            $dimension = SegmentDimension::tryFrom($dimStr);
            $operator = SegmentOperator::tryFrom($opStr);

            if ($dimension === null || $operator === null || $value === '') {
                return Response::json(['error' => 'Invalid filter: dimension, operator, and value are required'], 400);
            }

            $filters[] = new SegmentFilter(
                dimension: $dimension,
                operator: $operator,
                value: $value,
            );
        }

        $segment = $this->segmentService->create($siteId, $name, $filters);

        return Response::json([
            'id' => $segment->id,
            'site_id' => $segment->siteId,
            'name' => $segment->name,
            'created_at' => $segment->createdAt->format('c'),
        ], 201);
    }

    public function count(ServerRequestInterface $request, string $id): Response
    {
        $params = $request->getQueryParams();
        $rawFrom = $params['from'] ?? null;
        $rawTo = $params['to'] ?? null;
        $from = new DateTimeImmutable(is_string($rawFrom) ? $rawFrom : '-30 days');
        $to = new DateTimeImmutable(is_string($rawTo) ? $rawTo : 'now');

        try {
            $count = $this->segmentService->countVisitors($id, $from, $to);
        } catch (AnalyticsException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }

        return Response::json([
            'segment_id' => $id,
            'visitors' => $count,
        ]);
    }

    public function delete(ServerRequestInterface $request, string $id): Response
    {
        try {
            $this->segmentService->delete($id);
        } catch (AnalyticsException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }

        return Response::json(['id' => $id, 'status' => 'deleted']);
    }
}
