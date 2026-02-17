<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Http\Controller\Admin\PluginController;
use Pulsar\Extension\Cms\Plugins\CmsPluginManagerInterface;
use Pulsar\Extension\Cms\Plugins\InstalledCmsPlugin;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(PluginController::class)]
final class PluginControllerTest extends TestCase
{
    #[Test]
    public function index_returns_installed_plugins(): void
    {
        $plugin = $this->createPlugin('plugin-1', 'seo-optimizer', true);

        $manager = $this->createStub(CmsPluginManagerInterface::class);
        $manager->method('getInstalled')->willReturn([$plugin]);

        $settings = $this->createStub(SettingsServiceInterface::class);
        $controller = new PluginController(pluginManager: $manager, settingsService: $settings, rateLimiter: null);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $plugins */
        $plugins = $body['plugins'];
        self::assertCount(1, $plugins);
        self::assertSame('plugin-1', $plugins[0]['id']);
        self::assertSame('seo-optimizer', $plugins[0]['slug']);
        self::assertTrue($plugins[0]['is_enabled']);
    }

    #[Test]
    public function toggle_enables_plugin(): void
    {
        $enabled = $this->createPlugin('plugin-1', 'seo-optimizer', true);

        $manager = $this->createStub(CmsPluginManagerInterface::class);
        $manager->method('enable')->willReturn($enabled);

        $settings = $this->createStub(SettingsServiceInterface::class);
        $controller = new PluginController(pluginManager: $manager, settingsService: $settings, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(stepUp: true, parsedBody: [
            'enabled' => true,
        ]);

        $response = $controller->toggle($request, 'plugin-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['is_enabled']);
    }

    #[Test]
    public function toggle_returns_422_on_exception(): void
    {
        $manager = $this->createStub(CmsPluginManagerInterface::class);
        $manager->method('disable')->willThrowException(new CmsException('Plugin not found'));

        $settings = $this->createStub(SettingsServiceInterface::class);
        $controller = new PluginController(pluginManager: $manager, settingsService: $settings, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(stepUp: true, parsedBody: [
            'enabled' => false,
        ]);

        $response = $controller->toggle($request, 'nonexistent');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function settings_returns_plugin_settings(): void
    {
        $manager = $this->createStub(CmsPluginManagerInterface::class);
        $settings = $this->createStub(SettingsServiceInterface::class);
        $settings->method('getGroup')->willReturn(['key1' => 'value1', 'key2' => 42]);

        $controller = new PluginController(pluginManager: $manager, settingsService: $settings, rateLimiter: null);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->settings($request, 'seo-optimizer');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('seo-optimizer', $body['plugin_id']);

        /** @var array<string, mixed> $settingsData */
        $settingsData = $body['settings'];
        self::assertSame('value1', $settingsData['key1']);
    }

    #[Test]
    public function update_settings_stores_values(): void
    {
        $manager = $this->createStub(CmsPluginManagerInterface::class);
        $settings = $this->createStub(SettingsServiceInterface::class);

        $controller = new PluginController(pluginManager: $manager, settingsService: $settings, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'settings' => ['key1' => 'new_value', 'key2' => 99],
        ]);

        $response = $controller->updateSettings($request, 'seo-optimizer');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('seo-optimizer', $body['plugin_id']);
        self::assertSame('updated', $body['status']);
        self::assertSame(2, $body['keys_updated']);
    }

    #[Test]
    public function delete_returns_400_when_reason_too_short(): void
    {
        $manager = $this->createStub(CmsPluginManagerInterface::class);
        $settings = $this->createStub(SettingsServiceInterface::class);

        $controller = new PluginController(pluginManager: $manager, settingsService: $settings, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(stepUp: true, parsedBody: [
            'reason' => 'short',
        ]);

        $response = $controller->delete($request, 'plugin-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function delete_returns_success_with_valid_reason(): void
    {
        $manager = $this->createStub(CmsPluginManagerInterface::class);
        $settings = $this->createStub(SettingsServiceInterface::class);

        $controller = new PluginController(pluginManager: $manager, settingsService: $settings, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(stepUp: true, parsedBody: [
            'reason' => 'Plugin is no longer maintained and has security issues',
        ]);

        $response = $controller->delete($request, 'plugin-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('deleted', $body['status']);
    }

    #[Test]
    public function install_returns_400_without_file(): void
    {
        $manager = $this->createStub(CmsPluginManagerInterface::class);
        $settings = $this->createStub(SettingsServiceInterface::class);

        $controller = new PluginController(pluginManager: $manager, settingsService: $settings, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(stepUp: true, uploadedFiles: []);

        $response = $controller->install($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $manager = $this->createStub(CmsPluginManagerInterface::class);
        $settings = $this->createStub(SettingsServiceInterface::class);
        $controller = new PluginController(pluginManager: $manager, settingsService: $settings, rateLimiter: null);

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $manager = $this->createStub(CmsPluginManagerInterface::class);
        $settings = $this->createStub(SettingsServiceInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new PluginController(
            pluginManager: $manager,
            settingsService: $settings,
            rateLimiter: null,
            gate: $gate,
        );
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    private function createPlugin(string $id, string $slug, bool $isEnabled): InstalledCmsPlugin
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new InstalledCmsPlugin(
            id: $id,
            tenantId: null,
            slug: $slug,
            displayName: ucfirst($slug),
            version: '1.0.0',
            description: 'A test plugin',
            authorName: 'Pulsar',
            authorUrl: null,
            license: 'MIT',
            manifestHash: 'manifest_hash',
            packageHash: 'package_hash',
            provenanceVerified: true,
            signatureVerified: true,
            capabilities: ['content_filter'],
            bootOrder: 0,
            isEnabled: $isEnabled,
            storagePath: 'plugins/' . $slug,
            installedAt: $now,
            installedBy: 'admin-1',
            enabledAt: $isEnabled ? $now : null,
            enabledBy: $isEnabled ? 'admin-1' : null,
            disabledAt: null,
            deletedAt: null,
        );
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     * @param array<string, mixed>|null $uploadedFiles
     */
    private function createAuthenticatedRequest(
        bool $stepUp = false,
        ?array $parsedBody = null,
        ?array $uploadedFiles = null,
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/plugins');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                'step_up_verified' => $stepUp,
                default => $default,
            },
        );

        if ($parsedBody !== null) {
            $request->method('getParsedBody')->willReturn($parsedBody);
        }

        if ($uploadedFiles !== null) {
            $request->method('getUploadedFiles')->willReturn($uploadedFiles);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/plugins');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
