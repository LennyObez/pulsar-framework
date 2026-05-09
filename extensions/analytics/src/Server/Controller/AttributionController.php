<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\AttributionServiceInterface;
use Pulsar\Extension\Analytics\Domain\AttributionModel;
use Pulsar\Http\Message\Response;

use function is_string;

/**
 * Attribution modeling API controller.
 */
#[Internal(reason: 'Analytics API controller')]
final readonly class AttributionController
{
    public function __construct(
        private AttributionServiceInterface $attributionService,
    ) {}

    public function calculate(ServerRequestInterface $request): Response
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

        /** @var mixed $rawModel */
        $rawModel = $params['model'] ?? null;
        $modelStr = is_string($rawModel) ? $rawModel : 'last_touch';
        $model = AttributionModel::tryFrom($modelStr);

        if ($model === null) {
            return Response::json(['error' => 'Invalid model: ' . $modelStr], 400);
        }

        /** @var mixed $rawGoalId */
        $rawGoalId = $params['goal_id'] ?? null;
        $goalId = is_string($rawGoalId) && $rawGoalId !== '' ? $rawGoalId : null;

        $results = $this->attributionService->calculate($siteId, $from, $to, $model, $goalId);

        return Response::json([
            'model' => $model->value,
            'data' => array_map(static fn($r) => [
                'source' => $r->source,
                'conversions' => $r->conversions,
                'revenue' => $r->revenue,
                'weight' => $r->weight,
            ], $results),
        ]);
    }

    public function compare(ServerRequestInterface $request): Response
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

        $results = $this->attributionService->compareModels($siteId, $from, $to);

        $data = [];

        foreach ($results as $modelName => $modelResults) {
            $data[$modelName] = array_map(static fn($r) => [
                'source' => $r->source,
                'conversions' => $r->conversions,
                'revenue' => $r->revenue,
                'weight' => $r->weight,
            ], $modelResults);
        }

        return Response::json(['data' => $data]);
    }
}
