<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\ABTest\ExperimentResult;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\ABTest\ExperimentService;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_array;
use function is_float;
use function is_int;
use function is_string;

#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class ExperimentController extends AbstractAdminController
{
    public function __construct(
        private ExperimentService $experimentService,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.admin');

        $experiments = $this->experimentService->getRunningExperiments();

        $data = [
            'experiments' => array_map(static fn($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'content_id' => $e->contentId,
                'status' => $e->status->value,
                'traffic_percentage' => $e->trafficPercentage,
                'start_at' => $e->startAt?->format('c'),
                'end_at' => $e->endAt?->format('c'),
                'created_at' => $e->createdAt->format('c'),
            ], $experiments),
        ];

        return $this->respondWithView($request, 'admin.experiments.index', $data);
    }

    public function create(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.admin');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $name = is_string($body['name'] ?? null) ? $body['name'] : '';
        $contentId = is_string($body['content_id'] ?? null) ? $body['content_id'] : '';
        $trafficPercentage = is_float($body['traffic_percentage'] ?? null) ? $body['traffic_percentage'] : (float) 1.0;

        if ($name === '' || $contentId === '') {
            return Response::json(['error' => 'Name and content_id are required'], 400);
        }

        $experiment = $this->experimentService->createExperiment($name, $contentId, $trafficPercentage);

        // Add variants if provided
        /** @var list<array{name?: string, content_id?: string, weight?: int}> $variants */
        $variants = is_array($body['variants'] ?? null) ? $body['variants'] : [];

        $createdVariants = [];

        foreach ($variants as $variantData) {
            $variantName = is_string($variantData['name'] ?? null) ? $variantData['name'] : '';
            $variantContentId = is_string($variantData['content_id'] ?? null) ? $variantData['content_id'] : '';
            $weight = is_int($variantData['weight'] ?? null) ? $variantData['weight'] : 1;

            if ($variantName !== '' && $variantContentId !== '') {
                $createdVariants[] = $this->experimentService->addVariant(
                    $experiment->id,
                    $variantName,
                    $variantContentId,
                    $weight,
                );
            }
        }

        return Response::json([
            'id' => $experiment->id,
            'status' => $experiment->status->value,
            'variants' => array_map(static fn($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'content_id' => $v->contentId,
                'weight' => $v->weight,
            ], $createdVariants),
        ], 201);
    }

    public function show(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.admin');

        $experiment = $this->experimentService->getExperiment($id);

        if ($experiment === null) {
            return Response::json(['error' => 'Experiment not found'], 404);
        }

        $variants = $this->experimentService->getVariants($id);

        $data = [
            'experiment' => [
                'id' => $experiment->id,
                'name' => $experiment->name,
                'content_id' => $experiment->contentId,
                'status' => $experiment->status->value,
                'traffic_percentage' => $experiment->trafficPercentage,
                'start_at' => $experiment->startAt?->format('c'),
                'end_at' => $experiment->endAt?->format('c'),
                'created_at' => $experiment->createdAt->format('c'),
            ],
            'variants' => array_map(static fn($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'content_id' => $v->contentId,
                'weight' => $v->weight,
            ], $variants),
        ];

        return $this->respondWithView($request, 'admin.experiments.show', $data);
    }

    public function start(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.admin');

        try {
            $experiment = $this->experimentService->startExperiment($id);

            return Response::json([
                'id' => $experiment->id,
                'status' => $experiment->status->value,
                'start_at' => $experiment->startAt?->format('c'),
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function stop(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.admin');

        try {
            $experiment = $this->experimentService->stopExperiment($id);

            return Response::json([
                'id' => $experiment->id,
                'status' => $experiment->status->value,
                'end_at' => $experiment->endAt?->format('c'),
            ]);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function results(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.admin');

        $experiment = $this->experimentService->getExperiment($id);

        if ($experiment === null) {
            return Response::json(['error' => 'Experiment not found'], 404);
        }

        $results = $this->experimentService->getResults($id);

        $data = [
            'experiment_id' => $id,
            'experiment_name' => $experiment->name,
            'status' => $experiment->status->value,
            'results' => array_map(static fn(ExperimentResult $r) => [
                'variant_id' => $r->variantId,
                'variant_name' => $r->variantName,
                'impressions' => $r->impressions,
                'conversions' => $r->conversions,
                'conversion_rate' => $r->conversionRate,
                'confidence_level' => $r->confidenceLevel,
            ], $results),
        ];

        return $this->respondWithView($request, 'admin.experiments.results', $data);
    }
}
