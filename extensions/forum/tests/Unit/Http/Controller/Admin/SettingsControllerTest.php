<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Http\Controller\Admin\SettingsController;
use Pulsar\Http\Message\ServerRequest;

#[CoversClass(SettingsController::class)]
final class SettingsControllerTest extends TestCase
{
    private function makeIdentity(): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('admin-1');
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }

    #[Test]
    public function indexWithoutAuthThrows(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $controller = new SettingsController(new ForumConfig(), $gate);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/settings',
            headers: ['Accept' => 'application/json'],
        );

        $this->expectException(AuthenticationException::class);

        $controller->index($request);
    }

    #[Test]
    public function indexWithoutPermissionThrows(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new SettingsController(new ForumConfig(), $gate);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/settings',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $this->expectException(AuthorizationException::class);

        $controller->index($request);
    }

    #[Test]
    public function indexReturnsAllSettings(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $controller = new SettingsController(new ForumConfig(), $gate);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/settings',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertArrayHasKey('settings', $body);
        $settings = $body['settings'];

        // Verify core settings
        self::assertSame(25, $settings['threads_per_page']);
        self::assertSame(20, $settings['posts_per_page']);
        self::assertArrayHasKey('post_cooldown_seconds', $settings);
        self::assertArrayHasKey('allow_guest_viewing', $settings);
        self::assertArrayHasKey('max_title_length', $settings);
        self::assertArrayHasKey('max_body_length', $settings);
        self::assertArrayHasKey('edit_window_minutes', $settings);

        // Verify nested moderation section
        self::assertArrayHasKey('moderation', $settings);
        self::assertArrayHasKey('auto_hide_threshold', $settings['moderation']);

        // Verify nested reputation section
        self::assertArrayHasKey('reputation', $settings);
        self::assertArrayHasKey('points_per_thread', $settings['reputation']);

        // Verify nested badges section
        self::assertArrayHasKey('badges', $settings);
        self::assertArrayHasKey('enabled', $settings['badges']);
    }

    #[Test]
    public function indexReflectsCustomConfig(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $config = ForumConfig::fromArray([
            'threads_per_page' => 50,
            'posts_per_page' => 30,
        ]);

        $controller = new SettingsController($config, $gate);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/admin/forum/settings',
            headers: ['Accept' => 'application/json'],
        )->withAttribute('identity', $this->makeIdentity());

        $response = $controller->index($request);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(50, $body['settings']['threads_per_page']);
        self::assertSame(30, $body['settings']['posts_per_page']);
    }
}
