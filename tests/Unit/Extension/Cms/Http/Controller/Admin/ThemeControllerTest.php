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
use Pulsar\Extension\Cms\Http\Controller\Admin\ThemeController;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\PreviewSession;
use Pulsar\Extension\Cms\Themes\ThemeManagerInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ThemeController::class)]
final class ThemeControllerTest extends TestCase
{
    #[Test]
    public function index_returns_installed_themes(): void
    {
        $theme = $this->createTheme('theme-1', 'starter', true);

        $manager = $this->createStub(ThemeManagerInterface::class);
        $manager->method('getInstalled')->willReturn([$theme]);

        $controller = new ThemeController(themeManager: $manager, rateLimiter: null);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $themes */
        $themes = $body['themes'];
        self::assertCount(1, $themes);
        self::assertSame('theme-1', $themes[0]['id']);
        self::assertSame('starter', $themes[0]['slug']);
        self::assertTrue($themes[0]['is_active']);
    }

    #[Test]
    public function activate_returns_activated_theme(): void
    {
        $activated = $this->createTheme('theme-1', 'starter', true);

        $manager = $this->createStub(ThemeManagerInterface::class);
        $manager->method('activate')->willReturn($activated);

        $controller = new ThemeController(themeManager: $manager, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(stepUp: true);

        $response = $controller->activate($request, 'theme-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['is_active']);
    }

    #[Test]
    public function activate_returns_422_on_exception(): void
    {
        $manager = $this->createStub(ThemeManagerInterface::class);
        $manager->method('activate')->willThrowException(new CmsException('Theme not found'));

        $controller = new ThemeController(themeManager: $manager, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(stepUp: true);

        $response = $controller->activate($request, 'nonexistent');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function deactivate_returns_deactivated_theme(): void
    {
        $deactivated = $this->createTheme('theme-1', 'starter', false);

        $manager = $this->createStub(ThemeManagerInterface::class);
        $manager->method('deactivate')->willReturn($deactivated);

        $controller = new ThemeController(themeManager: $manager, rateLimiter: null);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->deactivate($request, 'theme-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($body['is_active']);
    }

    #[Test]
    public function preview_returns_session_data(): void
    {
        $expires = new DateTimeImmutable('+1 hour');
        $session = new PreviewSession(
            themeId: 'theme-1',
            token: 'preview-token-abc',
            userId: 'admin-1',
            expiresAt: $expires,
        );

        $manager = $this->createStub(ThemeManagerInterface::class);
        $manager->method('preview')->willReturn($session);

        $controller = new ThemeController(themeManager: $manager, rateLimiter: null);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->preview($request, 'theme-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('theme-1', $body['theme_id']);
        self::assertSame('preview-token-abc', $body['token']);
        self::assertIsString($body['preview_url']);
    }

    #[Test]
    public function delete_returns_400_when_reason_too_short(): void
    {
        $manager = $this->createStub(ThemeManagerInterface::class);

        $controller = new ThemeController(themeManager: $manager, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(stepUp: true, parsedBody: [
            'reason' => 'short',
        ]);

        $response = $controller->delete($request, 'theme-1');

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('10 characters', (string) $body['error']);
    }

    #[Test]
    public function delete_returns_success_with_valid_reason(): void
    {
        $manager = $this->createStub(ThemeManagerInterface::class);

        $controller = new ThemeController(themeManager: $manager, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(stepUp: true, parsedBody: [
            'reason' => 'No longer needed for this project deployment',
        ]);

        $response = $controller->delete($request, 'theme-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('deleted', $body['status']);
    }

    #[Test]
    public function install_returns_400_without_file(): void
    {
        $manager = $this->createStub(ThemeManagerInterface::class);

        $controller = new ThemeController(themeManager: $manager, rateLimiter: null);
        $request = $this->createAuthenticatedRequest(stepUp: true, uploadedFiles: []);

        $response = $controller->install($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $manager = $this->createStub(ThemeManagerInterface::class);
        $controller = new ThemeController(themeManager: $manager, rateLimiter: null);

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $manager = $this->createStub(ThemeManagerInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new ThemeController(themeManager: $manager, rateLimiter: null, gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    private function createTheme(string $id, string $slug, bool $isActive): InstalledTheme
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new InstalledTheme(
            id: $id,
            tenantId: null,
            slug: $slug,
            displayName: ucfirst($slug) . ' Theme',
            version: '1.0.0',
            description: 'A test theme',
            authorName: 'Pulsar',
            authorUrl: null,
            license: 'MIT',
            manifestHash: 'manifest_hash',
            packageHash: 'package_hash',
            provenanceVerified: true,
            signatureVerified: true,
            isActive: $isActive,
            storagePath: 'themes/' . $slug,
            installedAt: $now,
            installedBy: 'admin-1',
            activatedAt: $isActive ? $now : null,
            activatedBy: $isActive ? 'admin-1' : null,
            deactivatedAt: null,
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
        $uri->method('getPath')->willReturn('/admin/themes');

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
        $uri->method('getPath')->willReturn('/admin/themes');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
