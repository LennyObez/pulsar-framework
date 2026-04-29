<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\FunnelServiceInterface;
use Pulsar\Extension\Analytics\Domain\FunnelStep;
use Pulsar\Extension\Analytics\Domain\FunnelStepType;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;
use Pulsar\Http\Message\Response;

use function is_array;
use function is_int;
use function is_string;

/**
 * Funnel analysis API controller.
 */
#[Internal(reason: 'Analytics API controller')]
final readonly class FunnelController
{
    public function __construct(
        private FunnelServiceInterface $funnelService,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        /** @var mixed $rawSiteId */
        $rawSiteId = $params['site_id'] ?? null;
        $siteId = is_string($rawSiteId) ? $rawSiteId : '';

        if ($siteId === '') {
            return Response::json(['error' => 'site_id is required'], 400);
        }

        $funnels = $this->funnelService->listForSite($siteId);

        return Response::json([
            'data' => array_map(static fn($f) => [
                'id' => $f->id,
                'site_id' => $f->siteId,
                'name' => $f->name,
                'steps' => array_map(static fn($s) => [
                    'position' => $s->position,
                    'name' => $s->name,
                    'type' => $s->type->value,
                    'value' => $s->value,
                ], $f->steps),
                'created_at' => $f->createdAt->format('c'),
            ], $funnels),
        ]);
    }

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
        /** @var mixed $rawStepsRaw */
        $rawStepsRaw = $body['steps'] ?? null;
        $rawSteps = is_array($rawStepsRaw) ? $rawStepsRaw : [];

        if ($siteId === '' || $name === '' || $rawSteps === []) {
            return Response::json(['error' => 'site_id, name, and steps are required'], 400);
        }

        $steps = [];

        /** @var mixed $rawStep */
        foreach ($rawSteps as $i => $rawStep) {
            if (!is_array($rawStep) || !is_int($i)) {
                continue;
            }
            /** @var array<string, mixed> $rawStep */

            /** @var mixed $rawStepName */
            $rawStepName = $rawStep['name'] ?? null;
            $stepName = is_string($rawStepName) ? $rawStepName : '';
            /** @var mixed $rawStepType */
            $rawStepType = $rawStep['type'] ?? null;
            $stepTypeStr = is_string($rawStepType) ? $rawStepType : '';
            /** @var mixed $rawStepValue */
            $rawStepValue = $rawStep['value'] ?? null;
            $stepValue = is_string($rawStepValue) ? $rawStepValue : '';
            $stepType = FunnelStepType::tryFrom($stepTypeStr);

            if ($stepName === '' || $stepType === null || $stepValue === '') {
                return Response::json(['error' => "Invalid step at position {$i}"], 400);
            }

            $steps[] = new FunnelStep(
                position: $i + 1,
                name: $stepName,
                type: $stepType,
                value: $stepValue,
            );
        }

        $funnel = $this->funnelService->create($siteId, $name, $steps);

        return Response::json([
            'id' => $funnel->id,
            'site_id' => $funnel->siteId,
            'name' => $funnel->name,
            'created_at' => $funnel->createdAt->format('c'),
        ], 201);
    }

    public function evaluate(ServerRequestInterface $request, string $id): Response
    {
        $params = $request->getQueryParams();
        /** @var mixed $rawFrom */
        $rawFrom = $params['from'] ?? null;
        /** @var mixed $rawTo */
        $rawTo = $params['to'] ?? null;
        $from = new DateTimeImmutable(is_string($rawFrom) ? $rawFrom : '-30 days');
        $to = new DateTimeImmutable(is_string($rawTo) ? $rawTo : 'now');

        try {
            $result = $this->funnelService->evaluate($id, $from, $to);
        } catch (AnalyticsException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }

        return Response::json([
            'funnel_id' => $result->funnelId,
            'overall_conversion_rate' => $result->overallConversionRate,
            'steps' => array_map(static fn($s) => [
                'position' => $s->position,
                'name' => $s->name,
                'visitors' => $s->visitors,
                'drop_off_rate' => $s->dropOffRate,
                'conversion_rate' => $s->conversionRate,
            ], $result->steps),
        ]);
    }

    public function delete(ServerRequestInterface $request, string $id): Response
    {
        try {
            $this->funnelService->delete($id);
        } catch (AnalyticsException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }

        return Response::json(['id' => $id, 'status' => 'deleted']);
    }
}
