<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Server\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Contracts\GoalServiceInterface;
use Pulsar\Extension\Analytics\Domain\Goal;
use Pulsar\Extension\Analytics\Domain\GoalType;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;
use Pulsar\Extension\Analytics\Server\Controller\GoalController;
use Pulsar\Http\Message\ServerRequest;

use function json_decode;

use const JSON_THROW_ON_ERROR;

final class GoalControllerTest extends TestCase
{
    private GoalController $controller;
    private GoalServiceInterface&Stub $goalService;

    protected function setUp(): void
    {
        $this->goalService = $this->createStub(GoalServiceInterface::class);
        $this->controller = new GoalController($this->goalService);
    }

    #[Test]
    public function indexReturnsBadRequestWhenSiteIdMissing(): void
    {
        $request = new ServerRequest(method: 'GET', uri: '/analytics/api/goals');

        $response = $this->controller->index($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('site_id is required', $body['error']);
    }

    #[Test]
    public function indexReturnsBadRequestWhenSiteIdEmpty(): void
    {
        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/goals',
            queryParams: ['site_id' => ''],
        );

        $response = $this->controller->index($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function indexReturnsGoalsForSite(): void
    {
        $createdAt = new DateTimeImmutable('2025-03-01T12:00:00+00:00');
        $goals = [
            new Goal('g-1', 'site-001', 'Visit about page', GoalType::PageVisit, '/about', $createdAt),
            new Goal('g-2', 'site-001', 'Signup event', GoalType::CustomEvent, 'signup', $createdAt),
        ];
        $this->goalService->method('listForSite')->willReturn($goals);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/goals',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data']);
        self::assertSame('g-1', $body['data'][0]['id']);
        self::assertSame('page_visit', $body['data'][0]['goal_type']);
        self::assertSame('g-2', $body['data'][1]['id']);
        self::assertSame('custom_event', $body['data'][1]['goal_type']);
    }

    #[Test]
    public function indexReturnsEmptyArrayWhenNoGoals(): void
    {
        $this->goalService->method('listForSite')->willReturn([]);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/analytics/api/goals',
            queryParams: ['site_id' => 'site-001'],
        );

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([], $body['data']);
    }

    #[Test]
    public function createReturnsBadRequestWhenFieldsMissing(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/api/goals',
            parsedBody: ['site_id' => 'site-001'],
        );

        $response = $this->controller->create($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('required', $body['error']);
    }

    #[Test]
    public function createReturnsBadRequestForInvalidGoalType(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/api/goals',
            parsedBody: [
                'site_id' => 'site-001',
                'name' => 'Test Goal',
                'goal_type' => 'invalid_type',
                'target_value' => '/test',
            ],
        );

        $response = $this->controller->create($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Invalid goal_type', $body['error']);
    }

    #[Test]
    public function createReturns201OnSuccess(): void
    {
        $createdAt = new DateTimeImmutable('2025-03-01T12:00:00+00:00');
        $goal = new Goal('g-new', 'site-001', 'Test Goal', GoalType::PageVisit, '/test', $createdAt);
        $this->goalService->method('create')->willReturn($goal);

        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/api/goals',
            parsedBody: [
                'site_id' => 'site-001',
                'name' => 'Test Goal',
                'goal_type' => 'page_visit',
                'target_value' => '/test',
            ],
        );

        $response = $this->controller->create($request);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('g-new', $body['id']);
        self::assertSame('site-001', $body['site_id']);
        self::assertSame('page_visit', $body['goal_type']);
    }

    #[Test]
    public function createReturnsBadRequestWhenParsedBodyNull(): void
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/analytics/api/goals',
        );

        $response = $this->controller->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function showReturnsGoalWhenFound(): void
    {
        $createdAt = new DateTimeImmutable('2025-03-01T12:00:00+00:00');
        $goal = new Goal('g-1', 'site-001', 'About Page', GoalType::PageVisit, '/about', $createdAt);
        $this->goalService->method('findById')->willReturn($goal);

        $request = new ServerRequest(method: 'GET', uri: '/analytics/api/goals/g-1');

        $response = $this->controller->show('g-1');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('g-1', $body['id']);
        self::assertSame('About Page', $body['name']);
    }

    #[Test]
    public function showReturns404WhenGoalNotFound(): void
    {
        $this->goalService->method('findById')->willReturn(null);

        $request = new ServerRequest(method: 'GET', uri: '/analytics/api/goals/nonexistent');

        $response = $this->controller->show('nonexistent');

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('Goal not found', $body['error']);
    }

    #[Test]
    public function updateReturnsBadRequestWhenFieldsMissing(): void
    {
        $request = new ServerRequest(
            method: 'PUT',
            uri: '/analytics/api/goals/g-1',
            parsedBody: ['name' => 'Updated'],
        );

        $response = $this->controller->update($request, 'g-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function updateReturnsBadRequestForInvalidGoalType(): void
    {
        $request = new ServerRequest(
            method: 'PUT',
            uri: '/analytics/api/goals/g-1',
            parsedBody: [
                'name' => 'Updated',
                'goal_type' => 'bad_type',
                'target_value' => '/new',
            ],
        );

        $response = $this->controller->update($request, 'g-1');

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Invalid goal_type', $body['error']);
    }

    #[Test]
    public function updateReturnsUpdatedGoal(): void
    {
        $createdAt = new DateTimeImmutable('2025-03-01T12:00:00+00:00');
        $goal = new Goal('g-1', 'site-001', 'Updated Goal', GoalType::CustomEvent, 'purchase', $createdAt);
        $this->goalService->method('update')->willReturn($goal);

        $request = new ServerRequest(
            method: 'PUT',
            uri: '/analytics/api/goals/g-1',
            parsedBody: [
                'name' => 'Updated Goal',
                'goal_type' => 'custom_event',
                'target_value' => 'purchase',
            ],
        );

        $response = $this->controller->update($request, 'g-1');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('Updated Goal', $body['name']);
    }

    #[Test]
    public function updateReturns404WhenGoalNotFoundViaException(): void
    {
        $this->goalService->method('update')->willThrowException(
            AnalyticsException::notFound('Goal', 'g-missing'),
        );

        $request = new ServerRequest(
            method: 'PUT',
            uri: '/analytics/api/goals/g-missing',
            parsedBody: [
                'name' => 'Updated',
                'goal_type' => 'page_visit',
                'target_value' => '/test',
            ],
        );

        $response = $this->controller->update($request, 'g-missing');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function deleteReturnsSuccessResponse(): void
    {
        $request = new ServerRequest(method: 'DELETE', uri: '/analytics/api/goals/g-1');

        $response = $this->controller->delete('g-1');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('g-1', $body['id']);
        self::assertSame('deleted', $body['status']);
    }

    #[Test]
    public function deleteReturns404WhenGoalNotFound(): void
    {
        $this->goalService->method('delete')->willThrowException(
            AnalyticsException::notFound('Goal', 'g-missing'),
        );

        $request = new ServerRequest(method: 'DELETE', uri: '/analytics/api/goals/g-missing');

        $response = $this->controller->delete('g-missing');

        self::assertSame(404, $response->getStatusCode());
    }
}
