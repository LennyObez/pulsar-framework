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
use Pulsar\Extension\Cms\Http\Controller\Admin\RobotsController;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(RobotsController::class)]
final class RobotsControllerTest extends TestCase
{
    #[Test]
    public function show_returns_robots_content_from_settings(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturn("User-agent: Googlebot\nDisallow: /admin\n");

        $controller = new RobotsController(settings: $settings);
        $request = $this->createAuthenticatedRequest('application/json');

        $response = $controller->show($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame("User-agent: Googlebot\nDisallow: /admin\n", $body['content']);
    }

    #[Test]
    public function show_uses_default_when_no_setting_exists(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturn(null);

        $controller = new RobotsController(settings: $settings);
        $request = $this->createAuthenticatedRequest('application/json');

        $response = $controller->show($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame("User-agent: *\nAllow: /\n", $body['content']);
    }

    #[Test]
    public function show_throws_when_not_authenticated(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $controller = new RobotsController(settings: $settings);

        $request = $this->createUnauthenticatedRequest('application/json');

        $this->expectException(AuthenticationException::class);
        $controller->show($request);
    }

    #[Test]
    public function show_throws_when_authorization_denied(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new RobotsController(settings: $settings, gate: $gate);
        $request = $this->createAuthenticatedRequest('application/json');

        $this->expectException(AuthorizationException::class);
        $controller->show($request);
    }

    #[Test]
    public function update_saves_robots_content(): void
    {
        $settings = $this->createMock(SettingsServiceInterface::class);
        $settings->expects(self::once())->method('set')
            ->with('seo', 'robots_txt', "User-agent: *\nDisallow: /secret\n", null, 'robots.txt updated via admin');

        $controller = new RobotsController(settings: $settings);
        $request = $this->createAuthenticatedRequest('application/json', ['content' => "User-agent: *\nDisallow: /secret\n"]);

        $response = $controller->update($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('updated', $body['status']);
    }

    #[Test]
    public function update_returns_400_for_empty_content(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $controller = new RobotsController(settings: $settings);
        $request = $this->createAuthenticatedRequest('application/json', ['content' => '']);

        $response = $controller->update($request);

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Content is required', $body['error']);
    }

    #[Test]
    public function update_returns_400_when_content_key_missing(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $controller = new RobotsController(settings: $settings);
        $request = $this->createAuthenticatedRequest('application/json', []);

        $response = $controller->update($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function update_returns_400_when_content_is_non_string(): void
    {
        $settings = $this->createStub(SettingsServiceInterface::class);
        $controller = new RobotsController(settings: $settings);
        $request = $this->createAuthenticatedRequest('application/json', ['content' => 42]);

        $response = $controller->update($request);

        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function createAuthenticatedRequest(string $accept, ?array $parsedBody = null): ServerRequestInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/seo/robots');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn($accept);
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
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
        $uri->method('getPath')->willReturn('/admin/seo/robots');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn($accept);
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
