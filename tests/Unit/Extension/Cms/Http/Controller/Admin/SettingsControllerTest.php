<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Http\Controller\Admin\SettingsController;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(SettingsController::class)]
final class SettingsControllerTest extends TestCase
{
    #[Test]
    public function show_returns_settings_for_group(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $settings->method('getGroup')->willReturn(['site_name' => 'My Site', 'tagline' => 'Hello']);

        $config = new CmsConfig();
        $controller = new SettingsController(settingsService: $settings, config: $config);
        $request = $this->createAuthenticatedRequest('application/json');

        $response = $controller->show($request, 'general');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('general', $body['group']);
        self::assertSame(['site_name' => 'My Site', 'tagline' => 'Hello'], $body['settings']);
    }

    #[Test]
    public function show_throws_when_unauthenticated(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $config = new CmsConfig();
        $controller = new SettingsController(settingsService: $settings, config: $config);

        $request = $this->createUnauthenticatedRequest('application/json');

        $this->expectException(AuthenticationException::class);
        $controller->show($request, 'general');
    }

    #[Test]
    public function show_throws_when_authorization_denied(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $config = new CmsConfig();
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new SettingsController(settingsService: $settings, config: $config, gate: $gate);
        $request = $this->createAuthenticatedRequest('application/json');

        $this->expectException(AuthorizationException::class);
        $controller->show($request, 'general');
    }

    #[Test]
    public function show_uses_locale_from_query_params(): void
    {
        $settings = $this->createMock(SettingsServiceInterface::class);
        $settings->expects(self::once())->method('getGroup')->with('seo', 'fr')->willReturn([]);

        $config = new CmsConfig();
        $controller = new SettingsController(settingsService: $settings, config: $config);
        $request = $this->createAuthenticatedRequest('application/json', queryParams: ['locale' => 'fr']);

        $response = $controller->show($request, 'seo');

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('fr', $body['locale']);
    }

    #[Test]
    public function update_saves_settings_with_step_up(): void
    {
        $settings = $this->createMock(SettingsServiceInterface::class);
        $settings->expects(self::exactly(2))->method('set');

        $config = new CmsConfig();
        $controller = new SettingsController(settingsService: $settings, config: $config);

        $request = $this->createAuthenticatedRequest(
            'application/json',
            parsedBody: [
                'settings' => ['site_name' => 'Updated', 'tagline' => 'New'],
                'reason' => 'User updated settings',
            ],
            stepUp: true,
        );

        $response = $controller->update($request, 'general');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('updated', $body['status']);
        self::assertSame(2, $body['keys_updated']);
    }

    #[Test]
    public function update_throws_without_step_up(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $config = new CmsConfig();
        $controller = new SettingsController(settingsService: $settings, config: $config);

        $request = $this->createAuthenticatedRequest(
            'application/json',
            parsedBody: ['settings' => ['key' => 'val']],
            stepUp: false,
        );

        $this->expectException(AuthorizationException::class);
        $controller->update($request, 'general');
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     * @param array<string, string> $queryParams
     */
    private function createAuthenticatedRequest(
        string $accept,
        ?array $parsedBody = null,
        array $queryParams = [],
        bool $stepUp = false,
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/settings/general');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn($accept);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($queryParams);
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

        return $request;
    }

    private function createUnauthenticatedRequest(string $accept): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/settings/general');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn($accept);
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
