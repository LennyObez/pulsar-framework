<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Cms\Dashboard\DashboardService;
use Pulsar\Extension\Cms\Dashboard\DashboardWidgetInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\AssetController;
use Pulsar\Extension\Cms\Http\Controller\Admin\DashboardController;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowServiceInterface;

use function json_decode;
use function strtolower;

use const JSON_THROW_ON_ERROR;

#[CoversClass(DashboardController::class)]
#[CoversClass(AssetController::class)]
final class DashboardIntegrationTest extends TestCase
{
    #[Test]
    public function dashboard_controller_resolves_with_injected_services(): void
    {
        $widget = $this->createStub(DashboardWidgetInterface::class);
        $widget->method('getName')->willReturn('content_status');
        $widget->method('getData')->willReturn([
            'counts' => ['draft' => 3, 'published' => 7, 'scheduled' => 1, 'archived' => 0],
            'total' => 11,
        ]);
        $widget->method('getTemplate')->willReturn('dashboard/widgets/content-status');

        $dashboardService = new DashboardService([$widget]);
        $workflowService = $this->createStub(EditorialWorkflowServiceInterface::class);
        $workflowService->method('getPendingReviews')->willReturn([]);
        $settingsService = $this->createStub(SettingsServiceInterface::class);
        $settingsService->method('getAll')->willReturn(['general' => ['site_name' => 'Pulsar']]);

        $controller = new DashboardController(
            dashboardService: $dashboardService,
            workflowService: $workflowService,
            settingsService: $settingsService,
        );

        $request = $this->createHtmlRequest();
        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        // Without a template engine, the controller returns JSON via respondWithView fallback
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayHasKey('widgets', $body);

        /** @var array<string, mixed> $widgets */
        $widgets = $body['widgets'];
        self::assertArrayHasKey('content_status', $widgets);

        /** @var array<string, mixed> $contentWidget */
        $contentWidget = $widgets['content_status'];
        /** @var array<string, mixed> $contentData */
        $contentData = $contentWidget['data'] ?? [];
        self::assertSame(11, $contentData['total'] ?? 0);
    }

    #[Test]
    public function full_request_cycle_returns_complete_widget_data(): void
    {
        $controller = new DashboardController();
        $request = $this->createHtmlRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayHasKey('widgets', $body);

        // Core widget sections are always present
        /** @var array<string, mixed> $widgets */
        $widgets = $body['widgets'];
        self::assertArrayHasKey('pending_reviews', $widgets);
        self::assertArrayHasKey('site_settings', $widgets);
    }

    #[Test]
    public function css_asset_serving_returns_correct_mime_type(): void
    {
        $controller = new AssetController();
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null) => match ($name) {
                'path' => 'cms-admin.css',
                default => $default,
            },
        );

        $response = $controller->serve($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/css', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('cms-admin-shell', (string) $response->getBody());
    }

    private function createHtmlRequest(): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name) => match (strtolower($name)) {
                'accept' => 'text/html',
                default => '',
            },
        );
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
