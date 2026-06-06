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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function index(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        /** @var mixed $rawSiteId */
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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function create(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawSiteId */
        $rawSiteId = $body['site_id'] ?? null;
        $siteId = is_string($rawSiteId) ? $rawSiteId : '';
        /** @var mixed $rawName */
        $rawName = $body['name'] ?? null;
        $name = is_string($rawName) ? $rawName : '';
        /** @var mixed $rawFiltersRaw */
        $rawFiltersRaw = $body['filters'] ?? null;
        $rawFilters = is_array($rawFiltersRaw) ? $rawFiltersRaw : [];

        if ($siteId === '' || $name === '' || $rawFilters === []) {
            return Response::json(['error' => 'site_id, name, and filters are required'], 400);
        }

        $filters = [];

        /** @var mixed $rawFilter */
        foreach ($rawFilters as $rawFilter) {
            if (!is_array($rawFilter)) {
                continue;
            }
            /** @var array<string, mixed> $rawFilter */

            /** @var mixed $rawDim */
            $rawDim = $rawFilter['dimension'] ?? null;
            $dimStr = is_string($rawDim) ? $rawDim : '';
            /** @var mixed $rawOp */
            $rawOp = $rawFilter['operator'] ?? null;
            $opStr = is_string($rawOp) ? $rawOp : '';
            /** @var mixed $rawValue */
            $rawValue = $rawFilter['value'] ?? null;
            $value = is_string($rawValue) ? $rawValue : '';

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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function count(ServerRequestInterface $request, string $id): Response
    {
        $params = $request->getQueryParams();
        /** @var mixed $rawFrom */
        $rawFrom = $params['from'] ?? null;
        /** @var mixed $rawTo */
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
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function delete(string $id): Response
    {
        try {
            $this->segmentService->delete($id);
        } catch (AnalyticsException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }

        return Response::json(['id' => $id, 'status' => 'deleted']);
    }
}
