<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Server\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\GoalServiceInterface;
use Pulsar\Extension\Analytics\Domain\GoalType;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;
use Pulsar\Http\Message\Response;

/**
 * Goal CRUD API controller.
 */
#[Internal(reason: 'Analytics API controller')]
final readonly class GoalController
{
    public function __construct(
        private GoalServiceInterface $goalService,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $siteId = (string) ($params['site_id'] ?? '');

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

    public function create(ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $siteId = (string) ($body['site_id'] ?? '');
        $name = (string) ($body['name'] ?? '');
        $goalTypeStr = (string) ($body['goal_type'] ?? '');
        $targetValue = (string) ($body['target_value'] ?? '');

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

    public function show(ServerRequestInterface $request, string $id): Response
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

    public function update(ServerRequestInterface $request, string $id): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $name = (string) ($body['name'] ?? '');
        $goalTypeStr = (string) ($body['goal_type'] ?? '');
        $targetValue = (string) ($body['target_value'] ?? '');

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

    public function delete(ServerRequestInterface $request, string $id): Response
    {
        try {
            $this->goalService->delete($id);
        } catch (AnalyticsException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }

        return Response::json(['id' => $id, 'status' => 'deleted']);
    }
}
