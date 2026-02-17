<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\Auth\AuthenticatorInterface;
use Pulsar\Live\Auth\AuthResult;
use Pulsar\Live\Auth\AuthUiConfig;
use Pulsar\Live\Auth\UserProfile;

#[CoversClass(UserProfile::class)]
final class UserProfileTest extends TestCase
{
    #[Test]
    public function mountLoadsCurrentUser(): void
    {
        $auth = $this->createStub(AuthenticatorInterface::class);
        $auth->method('currentUser')->willReturn(['name' => 'John', 'email' => 'john@test.com']);

        $profile = new UserProfile();
        $profile->mount(['authenticator' => $auth, 'config' => new AuthUiConfig()]);

        self::assertSame('John', $profile->name);
        self::assertSame('john@test.com', $profile->email);
    }

    #[Test]
    public function renderShowsProfileSection(): void
    {
        $profile = new UserProfile();
        $profile->mount(['config' => new AuthUiConfig()]);

        $html = $profile->render();

        self::assertStringContainsString('Account Settings', $html);
        self::assertStringContainsString('Profile', $html);
        self::assertStringContainsString('Password', $html);
        self::assertStringContainsString('wire:model="name"', $html);
        self::assertStringContainsString('wire:model="email"', $html);
    }

    #[Test]
    public function renderShowsMfaTabWhenEnabled(): void
    {
        $profile = new UserProfile();
        $profile->mount(['config' => new AuthUiConfig(enableMfa: true)]);

        $html = $profile->render();

        self::assertStringContainsString('Two-Factor Auth', $html);
    }

    #[Test]
    public function renderHidesMfaTabWhenDisabled(): void
    {
        $profile = new UserProfile();
        $profile->mount(['config' => new AuthUiConfig(enableMfa: false)]);

        $html = $profile->render();

        self::assertStringNotContainsString('Two-Factor Auth', $html);
    }

    #[Test]
    public function switchSectionChangesActiveSection(): void
    {
        $profile = new UserProfile();
        $profile->mount(['config' => new AuthUiConfig()]);

        $profile->switchSection('password');

        self::assertSame('password', $profile->activeSection);

        $html = $profile->render();
        self::assertStringContainsString('Current password', $html);
        self::assertStringContainsString('New password', $html);
    }

    #[Test]
    public function updateProfileValidatesRequiredFields(): void
    {
        $profile = new UserProfile();
        $profile->mount(['config' => new AuthUiConfig()]);
        $profile->name = '';
        $profile->email = '';

        $profile->updateProfile();

        self::assertSame('Name and email are required.', $profile->profileError);
    }

    #[Test]
    public function updateProfileValidatesEmail(): void
    {
        $profile = new UserProfile();
        $profile->mount(['config' => new AuthUiConfig()]);
        $profile->name = 'John';
        $profile->email = 'not-an-email';

        $profile->updateProfile();

        self::assertStringContainsString('valid email', $profile->profileError);
    }

    #[Test]
    public function updateProfileSuccessful(): void
    {
        $auth = $this->createStub(AuthenticatorInterface::class);
        $auth->method('currentUser')->willReturn(['name' => 'Old', 'email' => 'old@test.com']);
        $auth->method('updateProfile')->willReturn(AuthResult::ok());

        $profile = new UserProfile();
        $profile->mount(['authenticator' => $auth, 'config' => new AuthUiConfig()]);
        $profile->name = 'New Name';
        $profile->email = 'new@test.com';

        $profile->updateProfile();

        self::assertSame('Profile updated successfully.', $profile->profileMessage);
        self::assertSame('', $profile->profileError);
    }

    #[Test]
    public function changePasswordValidatesMinLength(): void
    {
        $profile = new UserProfile();
        $profile->mount(['config' => new AuthUiConfig(passwordMinLength: 10)]);
        $profile->currentPassword = 'oldpass';
        $profile->newPassword = 'short';
        $profile->newPasswordConfirmation = 'short';

        $profile->changePassword();

        self::assertStringContainsString('at least 10 characters', $profile->passwordError);
    }

    #[Test]
    public function changePasswordValidatesConfirmation(): void
    {
        $profile = new UserProfile();
        $profile->mount(['config' => new AuthUiConfig()]);
        $profile->currentPassword = 'oldpass';
        $profile->newPassword = 'newpassword';
        $profile->newPasswordConfirmation = 'different';

        $profile->changePassword();

        self::assertStringContainsString('does not match', $profile->passwordError);
    }

    #[Test]
    public function changePasswordSuccessful(): void
    {
        $auth = $this->createStub(AuthenticatorInterface::class);
        $auth->method('currentUser')->willReturn(['name' => 'J', 'email' => 'j@t.com']);
        $auth->method('changePassword')->willReturn(AuthResult::ok());

        $profile = new UserProfile();
        $profile->mount(['authenticator' => $auth, 'config' => new AuthUiConfig()]);
        $profile->currentPassword = 'oldpass123';
        $profile->newPassword = 'newpass1234';
        $profile->newPasswordConfirmation = 'newpass1234';

        $profile->changePassword();

        self::assertSame('Password changed successfully.', $profile->passwordMessage);
        self::assertSame('', $profile->currentPassword);
        self::assertSame('', $profile->newPassword);
    }

    #[Test]
    public function logoutCallsAuthenticator(): void
    {
        $auth = $this->createMock(AuthenticatorInterface::class);
        $auth->method('currentUser')->willReturn(null);
        $auth->expects(self::once())->method('logout');

        $profile = new UserProfile();
        $profile->mount(['authenticator' => $auth, 'config' => new AuthUiConfig()]);

        $profile->logout();
    }

    #[Test]
    public function renderContainsSignOutButton(): void
    {
        $profile = new UserProfile();
        $profile->mount(['config' => new AuthUiConfig()]);

        $html = $profile->render();

        self::assertStringContainsString('Sign out', $html);
        self::assertStringContainsString('wire:click="logout"', $html);
    }
}
