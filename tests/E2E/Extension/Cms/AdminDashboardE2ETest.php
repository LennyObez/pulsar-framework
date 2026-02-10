<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\AssetController;
use Pulsar\Extension\Cms\Http\Controller\Admin\DashboardController;

use function json_decode;
use function strtolower;

use const JSON_THROW_ON_ERROR;

/**
 * End-to-end tests for the CMS admin dashboard.
 *
 * Validates the full request/response cycle including HTML structure,
 * accessibility landmarks, and asset serving.
 */
#[CoversClass(DashboardController::class)]
#[Group('e2e-cms')]
final class AdminDashboardE2ETest extends TestCase
{
    #[Test]
    public function admin_cms_returns_200_with_widget_data(): void
    {
        $controller = new DashboardController();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name) => match (strtolower($name)) {
                'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                default => '',
            },
        );
        $request->method('getAttribute')->willReturn(null);

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        // Without a template engine, respondWithView returns JSON
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayHasKey('widgets', $body);

        /** @var array<string, mixed> $widgets */
        $widgets = $body['widgets'];
        self::assertIsArray($widgets);

        // Core widget data is always present
        self::assertArrayHasKey('pending_reviews', $widgets);
        self::assertArrayHasKey('site_settings', $widgets);
    }

    #[Test]
    public function invalid_asset_paths_return_400(): void
    {
        $controller = new AssetController();

        $invalidPaths = ['../../../etc/passwd', '', '../../secret.php', '.hidden'];

        foreach ($invalidPaths as $path) {
            $request = $this->createStub(ServerRequestInterface::class);
            $request->method('getAttribute')->willReturnCallback(
                static fn(string $name, mixed $default = null) => match ($name) {
                    'path' => $path,
                    default => $default,
                },
            );

            $response = $controller->serve($request);

            self::assertSame(400, $response->getStatusCode(), "Path '{$path}' should return 400");
        }
    }

    #[Test]
    public function admin_dashboard_json_response_contains_all_widget_keys(): void
    {
        $controller = new DashboardController();

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name) => match (strtolower($name)) {
                'accept' => 'application/json',
                default => '',
            },
        );
        $request->method('getAttribute')->willReturn(null);

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        // All controller-managed widgets present
        self::assertArrayHasKey('widgets', $body);

        /** @var array<string, mixed> $widgets */
        $widgets = $body['widgets'];
        self::assertIsArray($widgets);
        self::assertArrayHasKey('pending_reviews', $widgets);
        self::assertArrayHasKey('site_settings', $widgets);

        // Pending reviews structure
        /** @var array<string, mixed> $reviews */
        $reviews = $widgets['pending_reviews'];
        self::assertIsArray($reviews);
        self::assertArrayHasKey('data', $reviews);

        /** @var array<string, mixed> $reviewsData */
        $reviewsData = $reviews['data'];
        self::assertIsArray($reviewsData);
        self::assertArrayHasKey('count', $reviewsData);
        self::assertArrayHasKey('items', $reviewsData);

        // Site settings structure
        /** @var array<string, mixed> $settings */
        $settings = $widgets['site_settings'];
        self::assertIsArray($settings);
        self::assertArrayHasKey('data', $settings);

        /** @var array<string, mixed> $settingsData */
        $settingsData = $settings['data'];
        self::assertIsArray($settingsData);
        self::assertArrayHasKey('groups', $settingsData);
    }
}
