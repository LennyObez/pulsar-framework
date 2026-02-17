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
use Pulsar\Extension\Cms\Http\Controller\Admin\LiveCssController;
use Pulsar\Extension\Cms\LiveCss\CssOverride;
use Pulsar\Extension\Cms\LiveCss\CssValidationResult;
use Pulsar\Extension\Cms\LiveCss\CssValidatorInterface;
use Pulsar\Extension\Cms\LiveCss\LiveCssServiceInterface;
use Pulsar\Extension\Cms\LiveCss\ThemeTokenResolverInterface;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\ThemeManagerInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(LiveCssController::class)]
final class LiveCssControllerTest extends TestCase
{
    #[Test]
    public function editor_returns_theme_with_tokens_and_overrides(): void
    {
        $theme = $this->createTheme('theme-1');

        $themeManager = $this->createStub(ThemeManagerInterface::class);
        $themeManager->method('getActive')->willReturn($theme);

        $tokenResolver = $this->createStub(ThemeTokenResolverInterface::class);
        $tokenResolver->method('getEditableTokens')->willReturn([]);

        $override = $this->createOverride('ovr-1');

        $liveCss = $this->createStub(LiveCssServiceInterface::class);
        $liveCss->method('getCurrentOverrides')->willReturn($override);

        $controller = $this->createController(
            liveCss: $liveCss,
            tokenResolver: $tokenResolver,
            themeManager: $themeManager,
        );
        $request = $this->createAuthenticatedRequest();

        $response = $controller->editor($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $themeData */
        $themeData = $body['theme'];
        self::assertSame('theme-1', $themeData['id']);
        self::assertSame('default', $themeData['slug']);
        self::assertIsArray($body['tokens']);
        self::assertNotNull($body['current_overrides']);
    }

    #[Test]
    public function editor_returns_404_when_no_active_theme(): void
    {
        $themeManager = $this->createStub(ThemeManagerInterface::class);
        $themeManager->method('getActive')->willReturn(null);

        $controller = $this->createController(themeManager: $themeManager);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->editor($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function save_returns_success_with_valid_css(): void
    {
        $validator = $this->createStub(CssValidatorInterface::class);
        $validator->method('validate')->willReturn(new CssValidationResult(
            isValid: true,
            errors: [],
            sanitizedCss: 'body { color: red; }',
        ));

        $override = $this->createOverride('ovr-new');

        $liveCss = $this->createStub(LiveCssServiceInterface::class);
        $liveCss->method('saveOverrides')->willReturn($override);

        $controller = $this->createController(liveCss: $liveCss, validator: $validator);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'theme_id' => 'theme-1',
            'css_content' => 'body { color: red; }',
            'reason' => 'Updated brand colors',
        ]);

        $response = $controller->save($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('saved', $body['status']);
        self::assertSame('ovr-new', $body['id']);
    }

    #[Test]
    public function save_returns_400_when_theme_id_missing(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'theme_id' => '',
            'css_content' => 'body {}',
            'reason' => 'Testing',
        ]);

        $response = $controller->save($request);

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('Theme ID', $body['error']);
    }

    #[Test]
    public function save_returns_400_when_reason_too_short(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'theme_id' => 'theme-1',
            'css_content' => 'body {}',
            'reason' => 'ab',
        ]);

        $response = $controller->save($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function save_returns_422_when_css_invalid(): void
    {
        $validator = $this->createStub(CssValidatorInterface::class);
        $validator->method('validate')->willReturn(new CssValidationResult(
            isValid: false,
            errors: ['Dangerous @import detected'],
            sanitizedCss: '',
        ));

        $controller = $this->createController(validator: $validator);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'theme_id' => 'theme-1',
            'css_content' => '@import url("evil.css");',
            'reason' => 'Testing invalid CSS',
        ]);

        $response = $controller->save($request);

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body['violations']);
    }

    #[Test]
    public function save_returns_422_on_service_exception(): void
    {
        $validator = $this->createStub(CssValidatorInterface::class);
        $validator->method('validate')->willReturn(new CssValidationResult(
            isValid: true,
            errors: [],
            sanitizedCss: 'body {}',
        ));

        $liveCss = $this->createStub(LiveCssServiceInterface::class);
        $liveCss->method('saveOverrides')->willThrowException(new CmsException('Theme not found'));

        $controller = $this->createController(liveCss: $liveCss, validator: $validator);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'theme_id' => 'nonexistent',
            'css_content' => 'body {}',
            'reason' => 'Testing error',
        ]);

        $response = $controller->save($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rollback_returns_success(): void
    {
        $override = $this->createOverride('ovr-prev');

        $liveCss = $this->createStub(LiveCssServiceInterface::class);
        $liveCss->method('rollback')->willReturn($override);

        $controller = $this->createController(liveCss: $liveCss);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'override_id' => 'ovr-1',
            'reason' => 'Reverting broken CSS',
        ]);

        $response = $controller->rollback($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('rolled_back', $body['status']);
    }

    #[Test]
    public function rollback_returns_400_when_override_id_missing(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'override_id' => '',
            'reason' => 'Testing',
        ]);

        $response = $controller->rollback($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function rollback_returns_400_when_reason_too_short(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'override_id' => 'ovr-1',
            'reason' => 'ab',
        ]);

        $response = $controller->rollback($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function history_returns_400_when_theme_id_missing(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(queryParams: []);

        $response = $controller->history($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function history_returns_version_list(): void
    {
        $override = $this->createOverride('ovr-1');

        $liveCss = $this->createStub(LiveCssServiceInterface::class);
        $liveCss->method('getVersionHistory')->willReturn([$override]);

        $controller = $this->createController(liveCss: $liveCss);
        $request = $this->createAuthenticatedRequest(queryParams: ['theme_id' => 'theme-1']);

        $response = $controller->history($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $data */
        $data = $body['data'];
        self::assertCount(1, $data);
        self::assertSame('ovr-1', $data[0]['id']);
    }

    #[Test]
    public function editor_throws_when_unauthenticated(): void
    {
        $controller = $this->createController();

        $this->expectException(AuthenticationException::class);
        $controller->editor($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function editor_throws_when_authorization_denied(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->createController(gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->editor($request);
    }

    private function createController(
        ?LiveCssServiceInterface $liveCss = null,
        ?CssValidatorInterface $validator = null,
        ?ThemeTokenResolverInterface $tokenResolver = null,
        ?ThemeManagerInterface $themeManager = null,
        ?GateInterface $gate = null,
    ): LiveCssController {
        return new LiveCssController(
            liveCss: $liveCss ?? $this->createStub(LiveCssServiceInterface::class),
            validator: $validator ?? $this->createStub(CssValidatorInterface::class),
            tokenResolver: $tokenResolver ?? $this->createStub(ThemeTokenResolverInterface::class),
            themeManager: $themeManager ?? $this->createStub(ThemeManagerInterface::class),
            gate: $gate,
        );
    }

    private function createTheme(string $id): InstalledTheme
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new InstalledTheme(
            id: $id,
            tenantId: null,
            slug: 'default',
            displayName: 'Default Theme',
            version: '1.0.0',
            description: 'The default theme',
            authorName: 'Pulsar',
            authorUrl: null,
            license: 'MIT',
            manifestHash: 'manifest_hash',
            packageHash: 'package_hash',
            provenanceVerified: true,
            signatureVerified: true,
            isActive: true,
            storagePath: 'themes/default',
            installedAt: $now,
            installedBy: 'admin-1',
            activatedAt: $now,
            activatedBy: 'admin-1',
            deactivatedAt: null,
            deletedAt: null,
        );
    }

    private function createOverride(string $id): CssOverride
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new CssOverride(
            id: $id,
            tenantId: null,
            themeId: 'theme-1',
            version: 1,
            cssContent: 'body { color: blue; }',
            cssHash: 'css_hash_value',
            tokenOverrides: ['primary-color' => '#0000ff'],
            isActive: true,
            createdAt: $now,
            createdBy: 'admin-1',
            reason: 'Brand color update',
        );
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     * @param array<string, string> $queryParams
     */
    private function createAuthenticatedRequest(
        ?array $parsedBody = null,
        array $queryParams = [],
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/live-css');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                'step_up_verified' => false,
                default => $default,
            },
        );

        if ($parsedBody !== null) {
            $request->method('getParsedBody')->willReturn($parsedBody);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/live-css');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
