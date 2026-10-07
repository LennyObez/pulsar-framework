<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Dashboard\DashboardService;
use Pulsar\Extension\Cms\Dashboard\DashboardWidgetInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\DashboardController;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Workflow\EditorialReview;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowServiceInterface;
use Pulsar\Extension\Cms\Workflow\ReviewStatus;

use function json_decode;
use function strtolower;

use const JSON_THROW_ON_ERROR;

#[CoversClass(DashboardController::class)]
final class DashboardControllerTest extends TestCase
{
    #[Test]
    public function json_response_when_accept_header_is_application_json(): void
    {
        $controller = new DashboardController();
        $request = $this->createRequest('application/json');

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayHasKey('widgets', $body);

        /** @var array<string, mixed> $widgets */
        $widgets = $body['widgets'];
        self::assertIsArray($widgets);
        self::assertArrayHasKey('pending_reviews', $widgets);
        self::assertArrayHasKey('site_settings', $widgets);
    }

    #[Test]
    public function json_fallback_when_no_template_engine_available(): void
    {
        $controller = new DashboardController();
        $request = $this->createRequest('text/html');

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        // Without a template engine, respondWithView falls back to JSON
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayHasKey('widgets', $body);
    }

    #[Test]
    public function dev_mode_with_all_null_dependencies(): void
    {
        $controller = new DashboardController();
        $request = $this->createRequest('application/json');

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayHasKey('widgets', $body);
    }

    #[Test]
    public function widget_data_from_dashboard_service(): void
    {
        $widget = $this->createStub(DashboardWidgetInterface::class);
        $widget->method('getName')->willReturn('content_status');
        $widget->method('getData')->willReturn([
            'counts' => ['draft' => 5, 'published' => 10, 'scheduled' => 2, 'archived' => 1],
            'total' => 18,
        ]);
        $widget->method('getTemplate')->willReturn('dashboard/widgets/content-status');

        $dashboardService = new DashboardService([$widget]);
        $controller = new DashboardController(dashboardService: $dashboardService);
        $request = $this->createRequest('application/json');

        $response = $controller->index($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $widgets */
        $widgets = $body['widgets'] ?? [];
        self::assertArrayHasKey('content_status', $widgets);
    }

    #[Test]
    public function auth_skipped_without_identity(): void
    {
        $gate = $this->createMock(GateInterface::class);
        $gate->expects(self::never())->method('denies');

        $controller = new DashboardController(gate: $gate);
        $request = $this->createRequest('text/html');

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function auth_denied_with_identity_throws_exception(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new DashboardController(gate: $gate);
        $request = $this->createRequest('text/html', $identity);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessageIsOrContains('Permission denied');

        $controller->index($request);
    }

    #[Test]
    public function auth_allowed_with_identity(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $controller = new DashboardController(gate: $gate);
        $request = $this->createRequest('text/html', $identity);

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function pending_reviews_included_in_widget_data(): void
    {
        $review = new EditorialReview(
            id: 'rev-001',
            contentId: 'content-123',
            locale: null,
            requestedBy: 'user-456',
            reviewerId: null,
            status: ReviewStatus::Pending,
            comment: null,
            decisionReason: null,
            createdAt: new DateTimeImmutable('2026-02-19T10:00:00+00:00'),
            decidedAt: null,
        );

        $workflowService = $this->createStub(EditorialWorkflowServiceInterface::class);
        $workflowService->method('getPendingReviews')->willReturn([$review]);

        $controller = new DashboardController(workflowService: $workflowService);
        $request = $this->createRequest('application/json');

        $response = $controller->index($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $widgets */
        $widgets = $body['widgets'] ?? [];
        self::assertArrayHasKey('pending_reviews', $widgets);

        /** @var array<string, mixed> $reviewWidget */
        $reviewWidget = $widgets['pending_reviews'];
        /** @var array<string, mixed> $reviewData */
        $reviewData = $reviewWidget['data'] ?? [];
        self::assertSame(1, $reviewData['count'] ?? 0);
        /** @var list<array{content_id?: string, requested_by?: string}> $items */
        $items = $reviewData['items'] ?? [];
        self::assertCount(1, $items);
        self::assertSame('content-123', $items[0]['content_id'] ?? '');
    }

    #[Test]
    public function settings_groups_added_to_widgets(): void
    {
        $settingsService = $this->createStub(SettingsServiceInterface::class);
        $settingsService->method('getAll')->willReturn([
            'general' => ['site_name' => 'Test'],
            'seo' => ['robots' => 'index'],
        ]);

        $controller = new DashboardController(settingsService: $settingsService);
        $request = $this->createRequest('application/json');

        $response = $controller->index($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayHasKey('widgets', $body);

        /** @var array<string, mixed> $widgets */
        $widgets = $body['widgets'];
        self::assertIsArray($widgets);
        self::assertArrayHasKey('site_settings', $widgets);

        /** @var array<string, mixed> $settingsWidget */
        $settingsWidget = $widgets['site_settings'];
        self::assertIsArray($settingsWidget);
        self::assertArrayHasKey('data', $settingsWidget);

        /** @var array<string, mixed> $settingsData */
        $settingsData = $settingsWidget['data'];
        self::assertIsArray($settingsData);
        self::assertSame(['general', 'seo'], $settingsData['groups'] ?? []);
    }

    #[Test]
    public function empty_widgets_return_empty_data_structures(): void
    {
        $controller = new DashboardController();
        $request = $this->createRequest('application/json');

        $response = $controller->index($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $widgets */
        $widgets = $body['widgets'] ?? [];

        // Core widgets should still be present even when empty
        self::assertArrayHasKey('pending_reviews', $widgets);
        self::assertArrayHasKey('site_settings', $widgets);
    }

    private function createRequest(string $accept, ?IdentityInterface $identity = null): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name) => match (strtolower($name)) {
                'accept' => $accept,
                default => '',
            },
        );
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name) => match ($name) {
                'identity' => $identity,
                default => null,
            },
        );

        return $request;
    }
}
