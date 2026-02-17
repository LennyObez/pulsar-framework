<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\BlockEditor\CoreBlocks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\BlockEditor\CoreBlocks\LoginFormBlock;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;

#[CoversClass(LoginFormBlock::class)]
final class LoginFormBlockTest extends TestCase
{
    private LoginFormBlock $block;
    private CsrfTokenManagerInterface&Stub $csrf;

    protected function setUp(): void
    {
        $this->csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $this->csrf->method('getToken')->willReturn('test-csrf-token');
        $this->block = new LoginFormBlock($this->csrf);
    }

    public function testType(): void
    {
        self::assertSame('login-form', $this->block->type());
    }

    public function testRenderLoginForm(): void
    {
        $html = $this->block->render([]);

        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('name="_csrf_token"', $html);
        self::assertStringContainsString('value="test-csrf-token"', $html);
        self::assertStringContainsString('type="email"', $html);
        self::assertStringContainsString('type="password"', $html);
        self::assertStringContainsString('autocomplete="email"', $html);
        self::assertStringContainsString('autocomplete="current-password"', $html);
        self::assertStringContainsString('>Log in</button>', $html);
    }

    public function testRenderShowsRememberMeByDefault(): void
    {
        $html = $this->block->render([]);
        self::assertStringContainsString('Remember me', $html);
    }

    public function testRenderHidesRememberMe(): void
    {
        $html = $this->block->render(['showRememberMe' => false]);
        self::assertStringNotContainsString('Remember me', $html);
    }

    public function testRenderShowsForgotPasswordByDefault(): void
    {
        $html = $this->block->render([]);
        self::assertStringContainsString('Forgot password?', $html);
    }

    public function testRenderHidesForgotPassword(): void
    {
        $html = $this->block->render(['showForgotPassword' => false]);
        self::assertStringNotContainsString('Forgot password?', $html);
    }

    public function testRenderShowsRegisterLink(): void
    {
        $html = $this->block->render(['registerUrl' => '/register']);
        self::assertStringContainsString('href="/register"', $html);
        self::assertStringContainsString('Create account', $html);
    }

    public function testRenderLoggedInState(): void
    {
        $html = $this->block->render([
            'isLoggedIn' => true,
            'username' => 'JaneDoe',
            'logoutUrl' => '/logout',
        ]);

        self::assertStringContainsString('Welcome, JaneDoe', $html);
        self::assertStringContainsString('href="/logout"', $html);
        self::assertStringContainsString('Log out', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function testRenderWithRedirectUrl(): void
    {
        $html = $this->block->render(['redirectUrl' => '/dashboard']);
        self::assertStringContainsString('name="_redirect"', $html);
        self::assertStringContainsString('value="/dashboard"', $html);
    }

    public function testRenderEscapesXss(): void
    {
        $html = $this->block->render([
            'action' => '"><script>xss</script>',
            'redirectUrl' => '"><img src=x>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testValidateAcceptsEmptyData(): void
    {
        $errors = $this->block->validate([]);
        self::assertSame([], $errors);
    }

    public function testValidateRejectsInvalidTypes(): void
    {
        $errors = $this->block->validate([
            'action' => 123,
            'showRememberMe' => 'yes',
        ]);

        self::assertCount(2, $errors);
    }
}
