<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\GoalServiceInterface;
use Pulsar\Extension\Analytics\Domain\GoalType;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;
use Pulsar\Http\Message\Response;

use function is_string;

/**
 * Goal CRUD API controller.
 */
#[Internal(reason: 'Analytics API controller')]
final readonly class GoalController
{
    public function __construct(
        private GoalServiceInterface $goalService,
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

        $goals = $this->goalService->listForSite($siteId);

        return Response::json([
            'data' => array_map(static fn($goal) => [
                'id' => $goal->id,
                'site_id' => $goal->siteId,
                'name' => $goal->name,
                'goal_type' => $goal->goalType->value,
                'target_value' => $goal->targetValue,
                'created_at' => $goal->createdAt->format('c'),
            ], $goals),
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
        /** @var mixed $rawGoalType */
        $rawGoalType = $body['goal_type'] ?? null;
        $goalTypeStr = is_string($rawGoalType) ? $rawGoalType : '';
        /** @var mixed $rawTargetValue */
        $rawTargetValue = $body['target_value'] ?? null;
        $targetValue = is_string($rawTargetValue) ? $rawTargetValue : '';

        if ($siteId === '' || $name === '' || $goalTypeStr === '' || $targetValue === '') {
            return Response::json(['error' => 'site_id, name, goal_type, and target_value are required'], 400);
        }

        $goalType = GoalType::tryFrom($goalTypeStr);

        if ($goalType === null) {
            return Response::json(['error' => 'Invalid goal_type: ' . $goalTypeStr], 400);
        }

        $goal = $this->goalService->create($siteId, $name, $goalType, $targetValue);

        return Response::json([
            'id' => $goal->id,
            'site_id' => $goal->siteId,
            'name' => $goal->name,
            'goal_type' => $goal->goalType->value,
            'target_value' => $goal->targetValue,
            'created_at' => $goal->createdAt->format('c'),
        ], 201);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function show(string $id): Response
    {
        $goal = $this->goalService->findById($id);

        if ($goal === null) {
            return Response::json(['error' => 'Goal not found'], 404);
        }

        return Response::json([
            'id' => $goal->id,
            'site_id' => $goal->siteId,
            'name' => $goal->name,
            'goal_type' => $goal->goalType->value,
            'target_value' => $goal->targetValue,
            'created_at' => $goal->createdAt->format('c'),
        ]);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function update(ServerRequestInterface $request, string $id): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawName */
        $rawName = $body['name'] ?? null;
        $name = is_string($rawName) ? $rawName : '';
        /** @var mixed $rawGoalType */
        $rawGoalType = $body['goal_type'] ?? null;
        $goalTypeStr = is_string($rawGoalType) ? $rawGoalType : '';
        /** @var mixed $rawTargetValue */
        $rawTargetValue = $body['target_value'] ?? null;
        $targetValue = is_string($rawTargetValue) ? $rawTargetValue : '';

        if ($name === '' || $goalTypeStr === '' || $targetValue === '') {
            return Response::json(['error' => 'name, goal_type, and target_value are required'], 400);
        }

        $goalType = GoalType::tryFrom($goalTypeStr);

        if ($goalType === null) {
            return Response::json(['error' => 'Invalid goal_type: ' . $goalTypeStr], 400);
        }

        try {
            $goal = $this->goalService->update($id, $name, $goalType, $targetValue);
        } catch (AnalyticsException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }

        return Response::json([
            'id' => $goal->id,
            'site_id' => $goal->siteId,
            'name' => $goal->name,
            'goal_type' => $goal->goalType->value,
            'target_value' => $goal->targetValue,
            'created_at' => $goal->createdAt->format('c'),
        ]);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function delete(string $id): Response
    {
        try {
            $this->goalService->delete($id);
        } catch (AnalyticsException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }

        return Response::json(['id' => $id, 'status' => 'deleted']);
    }
}
